<?php

declare(strict_types=1);

namespace Utopia\Queue\Broker\Redis;

final class Script
{
    public const string ENQUEUE = <<<'LUA'
local now = redis.call('TIME')
local payload = cjson.decode(ARGV[3])
local message = {
    pid = ARGV[1],
    queue = ARGV[2],
    timestamp = tonumber(now[1]),
    payload = payload
}
local encoded = '{"pid":' .. cjson.encode(message.pid)
    .. ',"queue":' .. cjson.encode(message.queue)
    .. ',"timestamp":' .. tostring(message.timestamp)
    .. ',"payload":' .. cjson.encode(message.payload) .. '}'
if ARGV[4] == '1' then
    redis.call('RPUSH', KEYS[1], encoded)
else
    redis.call('LPUSH', KEYS[1], encoded)
end
return 1
LUA;

    public const string CLAIM = <<<'LUA'
local raw = redis.call('RPOP', KEYS[1])
if not raw then
    return {0}
end

local valid, message = pcall(cjson.decode, raw)
if not valid
    or type(message) ~= 'table'
    or type(message.pid) ~= 'string'
    or message.pid == ''
    or type(message.queue) ~= 'string'
    or type(message.timestamp) ~= 'number'
    or type(message.payload) ~= 'table' then
    redis.call('LPUSH', KEYS[4], cjson.encode({reason = 'malformed_pending', raw = raw}))
    redis.call('INCR', KEYS[7])
    return {2}
end

if redis.call('HEXISTS', KEYS[2], message.pid) == 1 then
    redis.call('LPUSH', KEYS[4], cjson.encode({reason = 'duplicate_pid', raw = raw, pid = message.pid}))
    redis.call('INCR', KEYS[7])
    return {2}
end

local now = redis.call('TIME')
local claimedAt = now[1] .. ':' .. now[2]
local micros = (tonumber(now[1]) * 1000000) + tonumber(now[2])
local leaseUntil = micros + (tonumber(ARGV[1]) * 1000000)
local record = {
    message = message,
    state = 'processing',
    claimedAt = claimedAt,
    leaseUntil = leaseUntil
}

redis.call('HSET', KEYS[2], message.pid, cjson.encode(record))
redis.call('ZADD', KEYS[3], leaseUntil, message.pid)
redis.call('INCR', KEYS[5])
redis.call('INCR', KEYS[6])
return {1, raw, claimedAt}
LUA;

    public const string COMMIT = <<<'LUA'
local raw = redis.call('HGET', KEYS[1], ARGV[1])
if not raw or not redis.call('ZSCORE', KEYS[2], ARGV[1]) then
    return 0
end

local valid, record = pcall(cjson.decode, raw)
if not valid
    or type(record) ~= 'table'
    or record.state ~= 'processing'
    or record.claimedAt ~= ARGV[2] then
    return 0
end

redis.call('HDEL', KEYS[1], ARGV[1])
redis.call('ZREM', KEYS[2], ARGV[1])
redis.call('INCR', KEYS[3])
local processing = tonumber(redis.call('GET', KEYS[4]) or '0')
if processing > 0 then
    redis.call('DECR', KEYS[4])
end
return 1
LUA;

    public const string REJECT = <<<'LUA'
local raw = redis.call('HGET', KEYS[1], ARGV[1])
if not raw or not redis.call('ZSCORE', KEYS[2], ARGV[1]) then
    return 0
end

local valid, record = pcall(cjson.decode, raw)
if not valid
    or type(record) ~= 'table'
    or record.state ~= 'processing'
    or record.claimedAt ~= ARGV[2] then
    return 0
end

record.state = 'failed'
redis.call('HSET', KEYS[1], ARGV[1], cjson.encode(record))
redis.call('ZREM', KEYS[2], ARGV[1])
redis.call('LPUSH', KEYS[3], ARGV[1])
redis.call('INCR', KEYS[4])
local processing = tonumber(redis.call('GET', KEYS[5]) or '0')
if processing > 0 then
    redis.call('DECR', KEYS[5])
end
return 1
LUA;

    public const string RETRY = <<<'LUA'
local pid = redis.call('RPOP', KEYS[1])
if not pid then
    return {0}
end

local raw = redis.call('HGET', KEYS[2], pid)
local valid, record = pcall(cjson.decode, raw or '')
if not raw
    or not valid
    or type(record) ~= 'table'
    or record.state ~= 'failed'
    or type(record.message) ~= 'table'
    or type(record.message.queue) ~= 'string'
    or type(record.message.payload) ~= 'table' then
    redis.call('HDEL', KEYS[2], pid)
    redis.call('LPUSH', KEYS[4], cjson.encode({reason = 'malformed_failed', pid = pid, raw = raw or false}))
    redis.call('INCR', KEYS[6])
    return {2}
end

if redis.call('HEXISTS', KEYS[2], ARGV[1]) == 1 then
    redis.call('RPUSH', KEYS[1], pid)
    return {3}
end

local now = redis.call('TIME')
local message = {
    pid = ARGV[1],
    queue = record.message.queue,
    timestamp = tonumber(now[1]),
    payload = record.message.payload
}
local encoded = '{"pid":' .. cjson.encode(message.pid)
    .. ',"queue":' .. cjson.encode(message.queue)
    .. ',"timestamp":' .. tostring(message.timestamp)
    .. ',"payload":' .. cjson.encode(message.payload) .. '}'
redis.call('LPUSH', KEYS[3], encoded)
redis.call('HDEL', KEYS[2], pid)
redis.call('INCR', KEYS[5])
return {1, encoded}
LUA;

    public const string EXPIRED = <<<'LUA'
local now = redis.call('TIME')
local micros = (tonumber(now[1]) * 1000000) + tonumber(now[2])
local pids = redis.call('ZRANGEBYSCORE', KEYS[1], '-inf', micros, 'LIMIT', 0, tonumber(ARGV[1]))
local result = {}

for _, pid in ipairs(pids) do
    local raw = redis.call('HGET', KEYS[2], pid)
    local valid, record = pcall(cjson.decode, raw or '')
    local claimedAt = ''
    if raw
        and valid
        and type(record) == 'table'
        and record.state == 'processing'
        and type(record.claimedAt) == 'string' then
        claimedAt = record.claimedAt
    end
    table.insert(result, pid)
    table.insert(result, claimedAt)
end

return result
LUA;

    public const string RECLAIM = <<<'LUA'
local score = redis.call('ZSCORE', KEYS[1], ARGV[1])
if not score then
    return {0}
end

local now = redis.call('TIME')
local micros = (tonumber(now[1]) * 1000000) + tonumber(now[2])
if tonumber(score) > micros then
    return {0}
end

local raw = redis.call('HGET', KEYS[2], ARGV[1])
local valid, record = pcall(cjson.decode, raw or '')
if not raw
    or not valid
    or type(record) ~= 'table'
    or record.state ~= 'processing'
    or type(record.message) ~= 'table'
    or type(record.message.queue) ~= 'string'
    or type(record.message.timestamp) ~= 'number'
    or type(record.message.payload) ~= 'table' then
    redis.call('ZREM', KEYS[1], ARGV[1])
    redis.call('HDEL', KEYS[2], ARGV[1])
    redis.call('LPUSH', KEYS[4], cjson.encode({reason = 'missing_or_malformed_processing', pid = ARGV[1], raw = raw or false}))
    local processing = tonumber(redis.call('GET', KEYS[5]) or '0')
    if processing > 0 then
        redis.call('DECR', KEYS[5])
    end
    redis.call('INCR', KEYS[7])
    return {2}
end

if ARGV[2] == '' or record.claimedAt ~= ARGV[2] then
    return {0}
end

if redis.call('HEXISTS', KEYS[2], ARGV[3]) == 1 then
    return {3}
end

local message = {
    pid = ARGV[3],
    queue = record.message.queue,
    timestamp = record.message.timestamp,
    payload = record.message.payload
}
local encoded = '{"pid":' .. cjson.encode(message.pid)
    .. ',"queue":' .. cjson.encode(message.queue)
    .. ',"timestamp":' .. tostring(message.timestamp)
    .. ',"payload":' .. cjson.encode(message.payload) .. '}'
redis.call('RPUSH', KEYS[3], encoded)
redis.call('HDEL', KEYS[2], ARGV[1])
redis.call('ZREM', KEYS[1], ARGV[1])
local processing = tonumber(redis.call('GET', KEYS[5]) or '0')
if processing > 0 then
    redis.call('DECR', KEYS[5])
end
redis.call('INCR', KEYS[6])
return {1, encoded}
LUA;

    public const string EXTEND = <<<'LUA'
local raw = redis.call('HGET', KEYS[1], ARGV[1])
if not raw or not redis.call('ZSCORE', KEYS[2], ARGV[1]) then
    return 0
end

local valid, record = pcall(cjson.decode, raw)
if not valid
    or type(record) ~= 'table'
    or record.state ~= 'processing'
    or record.claimedAt ~= ARGV[2] then
    return 0
end

local now = redis.call('TIME')
local micros = (tonumber(now[1]) * 1000000) + tonumber(now[2])
local leaseUntil = micros + (tonumber(ARGV[3]) * 1000000)
record.leaseUntil = leaseUntil
redis.call('HSET', KEYS[1], ARGV[1], cjson.encode(record))
redis.call('ZADD', KEYS[2], 'XX', leaseUntil, ARGV[1])
return 1
LUA;
}
