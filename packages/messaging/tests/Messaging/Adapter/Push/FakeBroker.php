<?php

/**
 * Minimal in-process MQTT 5 broker for exercising the Appwrite Push publisher.
 *
 * Spawned as a separate PHP process by AppwriteTest. It speaks just enough of the
 * protocol to accept the adapter's enhanced-auth CONNECT, ack QoS 1 PUBLISHes
 * (optionally rejecting configured tokens), and record what it saw to a capture
 * file the test reads back. Not a real broker — no auth, no fan-out.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use Swoole\Server;
use Swoole\Timer;
use Utopia\Messaging\Helpers\MQTT;

$argv = $_SERVER['argv'];
[$_, $port, $capturePath, $stateFile] = $argv;
$port = (int) $port;
$state = json_decode(file_get_contents($stateFile), true) ?: [];
$rejectTokens = $state['reject'] ?? [];

$captured = [
    'connect' => [],
    'publishes' => [],
];

$flush = function () use (&$captured, $capturePath): void {
    file_put_contents($capturePath, json_encode($captured));
};
$flush();

/** @var array<int, string> $buffers */
$buffers = [];

$server = new Server('127.0.0.1', $port, SWOOLE_BASE, SWOOLE_SOCK_TCP);
$server->set([
    'worker_num' => 1,
    'max_request' => 0,
    'log_level' => SWOOLE_LOG_ERROR,
    'open_eof_check' => false,
    'open_tcp_nodelay' => true,
]);

$server->on('start', function (): void {
    Timer::after(15000, function (): void {
        \Swoole\Event::exit();
    });
});

$server->on('close', function (Server $server, int $fd) use (&$buffers, $flush): void {
    unset($buffers[$fd]);
    $flush();
});

$server->on('receive', function (Server $server, int $fd, int $reactorId, string $data) use (&$captured, &$buffers, $rejectTokens, $flush): void {
    $buffers[$fd] = ($buffers[$fd] ?? '') . $data;

    while (($packet = MQTT::decodePacket($buffers[$fd])) !== null) {
        switch ($packet['type']) {
            case MQTT::PACKET_CONNECT:
                $parsed = MQTT::parseConnect($packet['payload']);
                $properties = $parsed['properties'];
                $captured['connect'] = [
                    'clientId' => $parsed['clientId'],
                    'authMethod' => (string) ($properties['authenticationMethod'] ?? ''),
                    'credential' => (string) ($properties['authenticationData'] ?? ''),
                    'projectId' => (string) ($properties['userProperties']['projectId'] ?? ''),
                ];
                $server->send($fd, MQTT::encodeConnack(MQTT::REASON_SUCCESS));
                $flush();
                break;

            case MQTT::PACKET_PUBLISH:
                $parsed = MQTT::parsePublish($packet['payload'], $packet['flags']);
                $captured['publishes'][] = [
                    'topic' => $parsed['topic'],
                    'payload' => $parsed['payload'],
                    'qos' => $parsed['qos'],
                ];

                $reason = MQTT::REASON_SUCCESS;
                foreach ($rejectTokens as $bad) {
                    if (str_ends_with($parsed['topic'], '/' . $bad)) {
                        $reason = 0x10;
                        break;
                    }
                }

                if ($parsed['qos'] === 1 && $parsed['packetId'] !== null) {
                    $server->send($fd, MQTT::encodePuback($parsed['packetId'], $reason));
                }
                $flush();
                break;

            case MQTT::PACKET_DISCONNECT:
                $server->close($fd);
                $flush();
                Timer::after(50, fn() => \Swoole\Event::exit());
                return;

            case MQTT::PACKET_PINGREQ:
                $server->send($fd, MQTT::encodePingresp());
                break;

            default:
                break;
        }
    }
});

$server->start();

$flush();
