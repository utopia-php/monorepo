<?php

declare(strict_types=1);

namespace Utopia\Queue\Broker\Redis;

final class Script
{
    public const string ENQUEUE = <<<'LUA'
local now = redis.call('TIME')
local valid, payload = pcall(cjson.decode, ARGV[3])
if not valid or type(payload) ~= 'table' then
    return 0
end
local encoded = '{"pid":' .. cjson.encode(ARGV[1])
    .. ',"queue":' .. cjson.encode(ARGV[2])
    .. ',"timestamp":' .. tostring(tonumber(now[1]))
    .. ',"payload":' .. ARGV[3] .. '}'
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
local marker = ',"payload":'
local payloadStart = string.find(raw, marker, 1, true)
local encodedPayload = nil
if payloadStart and string.sub(raw, -1) == '}' then
    encodedPayload = string.sub(raw, payloadStart + string.len(marker), -2)
end
local payloadValid, payload = pcall(cjson.decode, encodedPayload or '')
if not valid
    or type(message) ~= 'table'
    or type(message.pid) ~= 'string'
    or message.pid == ''
    or type(message.queue) ~= 'string'
    or type(message.timestamp) ~= 'number'
    or type(message.payload) ~= 'table'
    or not encodedPayload
    or encodedPayload == ''
    or not payloadValid
    or type(payload) ~= 'table' then
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
    queue = message.queue,
    timestamp = message.timestamp,
    payload = encodedPayload,
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
local payloadValid = false
local payload = nil
if valid and type(record) == 'table' then
    payloadValid, payload = pcall(cjson.decode, record.payload or '')
end
if not raw
    or not valid
    or type(record) ~= 'table'
    or record.state ~= 'failed'
    or type(record.queue) ~= 'string'
    or record.queue == ''
    or type(record.timestamp) ~= 'number'
    or type(record.payload) ~= 'string'
    or record.payload == ''
    or not payloadValid
    or type(payload) ~= 'table' then
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
local replacement = {
    pid = ARGV[1],
    queue = record.queue,
    timestamp = tonumber(now[1]),
}
local encoded = '{"pid":' .. cjson.encode(replacement.pid)
    .. ',"queue":' .. cjson.encode(replacement.queue)
    .. ',"timestamp":' .. tostring(replacement.timestamp)
    .. ',"payload":' .. record.payload .. '}'
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
local payloadValid = false
local payload = nil
if valid and type(record) == 'table' then
    payloadValid, payload = pcall(cjson.decode, record.payload or '')
end
if not raw
    or not valid
    or type(record) ~= 'table'
    or record.state ~= 'processing'
    or type(record.claimedAt) ~= 'string'
    or record.claimedAt == ''
    or type(record.queue) ~= 'string'
    or record.queue == ''
    or type(record.timestamp) ~= 'number'
    or type(record.payload) ~= 'string'
    or record.payload == ''
    or not payloadValid
    or type(payload) ~= 'table' then
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

local replacement = {
    pid = ARGV[3],
    queue = record.queue,
    timestamp = record.timestamp,
}
local encoded = '{"pid":' .. cjson.encode(replacement.pid)
    .. ',"queue":' .. cjson.encode(replacement.queue)
    .. ',"timestamp":' .. tostring(replacement.timestamp)
    .. ',"payload":' .. record.payload .. '}'
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
