<?php

declare(strict_types=1);

use KornSW\UjmwQueue\Http\RelayPath;
use KornSW\UjmwQueue\Processors\Liveness;
use KornSW\UjmwQueue\Processors\LockState;
use KornSW\UjmwQueue\Processors\ProcessorName;
use KornSW\UjmwQueue\Protocol\AsyncId;
use KornSW\UjmwQueue\Protocol\CallbackBuffer;
use KornSW\UjmwQueue\Protocol\Json;
use KornSW\UjmwQueue\Protocol\MethodFilter;
use KornSW\UjmwQueue\Protocol\PollDelay;
use KornSW\UjmwQueue\Protocol\PollingAuth;
use KornSW\UjmwQueue\Protocol\RawJson;
use KornSW\UjmwQueue\Protocol\RequeuePosition;
use KornSW\UjmwQueue\Security\RoutePattern;

// --- PollingAuth (reference values computed independently: Base64Url(SHA-256(secret + salt)) without padding)

TestRun::equals('e8iNBQxbZnxBwmwN2qwAoreN1bcmWCHe-2OmeegA5bk', PollingAuth::computeHash('Zt8kQ2xW', 'a8Kd3x'), 'polling hash reference value');
TestRun::check(PollingAuth::verify('Zt8kQ2xW', 'a8Kd3x', 'e8iNBQxbZnxBwmwN2qwAoreN1bcmWCHe-2OmeegA5bk'), 'polling hash verifies');
TestRun::check(!PollingAuth::verify('Zt8kQ2xW', 'a8Kd3y', 'e8iNBQxbZnxBwmwN2qwAoreN1bcmWCHe-2OmeegA5bk'), 'wrong salt is rejected');
TestRun::check(!PollingAuth::verify(null, 'a8Kd3x', 'x'), 'missing secret is rejected');
TestRun::check(!PollingAuth::verify('Zt8kQ2xW', '', PollingAuth::computeHash('Zt8kQ2xW', '')), 'empty salt is rejected');
TestRun::check(preg_match('/^[A-Za-z0-9]{8}$/', PollingAuth::generateSecret()) === 1, 'secret has 8 alphanumeric characters');

// --- AsyncId

$nowMs = 1794398400000; // 2026-11-11T12:00:00Z
$asyncId = AsyncId::create($nowMs);
TestRun::check(AsyncId::isValid($asyncId), 'generated id is valid', $asyncId);
TestRun::equals($nowMs, AsyncId::decodeUnixTimeMs($asyncId), 'creation time can be decoded');
TestRun::check(((int) $asyncId) < (1 << 53), 'id fits into 53 bits');
TestRun::check(!AsyncId::isValid('0123'), 'leading zero is invalid');
TestRun::check(!AsyncId::isValid('12a'), 'non-digits are invalid');
TestRun::check(!AsyncId::isValid(null), 'null is invalid');

// --- MethodFilter

$filter = MethodFilter::parse('*');
TestRun::check($filter->matchesAll() && $filter->matches('Anything'), "'*' matches everything");
$filter = MethodFilter::parse('RenderReport, RenderPreview');
TestRun::check($filter->matches('renderreport') && !$filter->matches('Other'), 'whitelist matches case-insensitively');
$filter = MethodFilter::parse('!RenderReport,!RenderPreview');
TestRun::check(!$filter->matches('RenderReport') && $filter->matches('Other') && $filter->isBlacklist(), 'blacklist excludes');
foreach (['m1,!m2', '', '!', 'a b', '_queue'] as $invalidFilter) {
  $isRejected = false;
  try {
    MethodFilter::parse($invalidFilter);
  } catch (InvalidArgumentException $exception) {
    $isRejected = true;
  }
  TestRun::check($isRejected, "invalid filter '" . $invalidFilter . "' is rejected");
}

// --- PollDelay (port of UjmwAsyncProtocol.GetFibonacciWindowSeconds / CalculateDefaultPollDelaySeconds)

$windowCases = [[0.0, 1], [1.5, 1], [2.0, 1], [3.0, 2], [4.9, 2], [5.0, 3], [9.0, 5], [13.0, 8], [21.0, 13]];
foreach ($windowCases as [$elapsedSeconds, $expected]) {
  TestRun::equals($expected, PollDelay::getFibonacciWindowSeconds($elapsedSeconds), 'fibonacci window after ' . $elapsedSeconds . ' s');
}
TestRun::equals(10, PollDelay::calculateSeconds(9.0, 100, 1), 'throttling doubles at 100 active operations');
TestRun::equals(15, PollDelay::calculateSeconds(9.0, 100, 2), 'throttling level 2 triples at 100 active operations');

// --- RequeuePosition (Q-REQUEUE)

TestRun::equals(0, RequeuePosition::getIndexForRelease(1), 'first release goes to the head');
TestRun::equals(5, RequeuePosition::getIndexForRelease(5), 'fifth release goes to index 5');
TestRun::equals(55, RequeuePosition::getIndexForRelease(10), 'tenth release goes to index 55');
TestRun::equals(null, RequeuePosition::getIndexForRelease(11), 'eleventh release terminates');
TestRun::equals(9.0, RequeuePosition::calculateSortPosition(0, [10.0, 11.0]), 'index 0 is before the first entry');
TestRun::equals(10.5, RequeuePosition::calculateSortPosition(1, [10.0, 11.0]), 'index 1 is between first and second');
TestRun::equals(12.0, RequeuePosition::calculateSortPosition(5, [10.0, 11.0]), 'short queue: appended at the end');
TestRun::equals(0.0, RequeuePosition::calculateSortPosition(3, []), 'empty queue');

// --- Json pass-through

$raw = '{"reportId":42,"amount":1.0,"empty":{},"list":[],"_":{"tenantId":"x/y"}}';
TestRun::equals('{"a":1,"request":' . $raw . ',"b":null}', Json::buildObject(['a' => 1, 'request' => new RawJson($raw), 'b' => null]), 'raw JSON is embedded byte-for-byte');
TestRun::equals('{"return":5,"async":{"execState":2}}', Json::addMembers('{"return":5}', ['async' => ['execState' => 2]]), 'members are spliced without re-encoding');
TestRun::equals('{"async":{"execState":2}}', Json::addMembers(' { } ', ['async' => ['execState' => 2]]), 'members are added to an empty object');
$merged = Json::decodeObject(Json::addMembers('{"pInvokes":[{"#":3}],"fault":null}', ['pInvokes' => [(object) ['#' => 1]]]));
TestRun::equals(2, count($merged->pInvokes), 'colliding callback arrays are concatenated');
TestRun::check(Json::decodeObject('[1,2]') === null && Json::decodeObject('nope') === null, 'only JSON objects are accepted');

// --- CallbackBuffer

$buffer = CallbackBuffer::parseInvocations('{"reportProgressInvokes":[{"#":1,"arg1":"Rendering"}],"other":5}');
TestRun::equals(['reportProgressInvokes'], array_keys($buffer), 'only ...Invokes properties are buffered');
$buffer = CallbackBuffer::merge($buffer, CallbackBuffer::parseInvocations('{"reportProgressInvokes":[{"#":2}],"doneInvokes":[{"#":3}]}'));
TestRun::equals(3, CallbackBuffer::countInvocations($buffer), 'merged invocations are counted');
$restored = CallbackBuffer::decodeStored(CallbackBuffer::encodeStored($buffer));
TestRun::equals(3, CallbackBuffer::countInvocations($restored), 'stored buffer round-trips');
TestRun::equals(null, CallbackBuffer::encodeStored([]), 'empty buffer is stored as null');
$isRejected = false;
try {
  CallbackBuffer::parseInvocations('{"xInvokes":5}');
} catch (InvalidArgumentException $exception) {
  $isRejected = true;
}
TestRun::check($isRejected, 'non-array invocations are rejected');

// --- RelayPath and RoutePattern

$path = RelayPath::parse('api/reports/_queue');
TestRun::check($path !== null && $path->isQueueEndpoint() && $path->getParentRoute() === 'api/reports', 'queue path is recognized');
$path = RelayPath::parse('/api/reports/RenderReport/');
TestRun::check($path !== null && !$path->isQueueEndpoint() && $path->getParentRoute() === 'api/reports' && $path->getLastSegment() === 'RenderReport', 'call path is split into route and method');
TestRun::check(RelayPath::parse('api/../secret') === null && RelayPath::parse('') === null && RelayPath::parse('a/.hidden') === null, 'invalid paths are rejected');
TestRun::equals('api/reports', RelayPath::toRouteKey('/Api/Reports/'), 'route key is lower case without slashes');
TestRun::check(RoutePattern::matches('*', 'anything/here'), "'*' grants all routes");
TestRun::check(RoutePattern::matches('api/reports*, other', 'api/reports/v2'), 'prefix pattern matches');
TestRun::check(!RoutePattern::matches('api/reports', 'api/reports2'), 'exact pattern does not match a longer route');
TestRun::check(RoutePattern::matches('API/Reports', 'api/reports'), 'patterns are case-insensitive');

// --- Processors: names, liveness, locks

$name = ProcessorName::parse('reportAgent#pid-4711');
TestRun::check($name->agentName === 'reportAgent' && $name->instanceName === 'pid-4711', "'#' separates agent and instance");
$name = ProcessorName::parse('agent#a#b');
TestRun::check($name->agentName === 'agent' && $name->instanceName === 'a#b', 'only the first # separates');
$name = ProcessorName::parse('worker1');
TestRun::check($name->agentName === 'worker1' && $name->instanceName === null, 'names without # get a synthetic instance');
$name = ProcessorName::parse('worker1#');
TestRun::check($name->agentName === 'worker1' && $name->instanceName === null, 'an empty discriminator counts as missing');
TestRun::equals('unnamed', ProcessorName::parse(null)->agentName, 'missing name -> unnamed');
TestRun::equals('unnamed', ProcessorName::parse('#x')->agentName, 'missing agent part -> unnamed');
TestRun::equals('03-07#14-05-09', ProcessorName::createSyntheticInstanceName(gmmktime(14, 5, 9, 3, 7, 2026), new DateTimeZone('UTC')), 'synthetic discriminator MM-dd#HH-mm-ss');

$nowMs = 10000000000;
$silentMs = 180000;
TestRun::equals(Liveness::IDLE, Liveness::of($nowMs - 1000, $nowMs, 0, $silentMs), 'recent contact without jobs -> idle');
TestRun::equals(Liveness::BUSY, Liveness::of($nowMs - 1000, $nowMs, 2, $silentMs), 'recent contact with jobs -> busy');
TestRun::equals(Liveness::SILENT, Liveness::of($nowMs - 180000, $nowMs, 2, $silentMs), 'configured silence (3 min) -> silent (yellow)');
TestRun::equals(Liveness::OFFLINE, Liveness::of($nowMs - 3600000, $nowMs, 0, $silentMs), '60 minutes silence -> offline');
TestRun::check(Liveness::isAvailable($nowMs - 179999, $nowMs, $silentMs) && !Liveness::isAvailable($nowMs - 180000, $nowMs, $silentMs), 'available only while green');

TestRun::equals(LockState::NONE, LockState::of(0, 3), 'level 0 -> none');
TestRun::equals(LockState::DRAINING, LockState::of(1, 3), 'drain with jobs -> draining (orange)');
TestRun::equals(LockState::LOCKED, LockState::of(1, 0), 'drain without jobs -> locked (red)');
TestRun::equals(LockState::LOCKED, LockState::of(2, 0), 'kill -> locked (red)');
TestRun::equals(LockState::LEVEL_DRAIN, LockState::getNextLevel(LockState::NONE), 'click on grey -> drain');
TestRun::equals(LockState::LEVEL_KILL, LockState::getNextLevel(LockState::DRAINING), 'click on orange -> kill');
TestRun::equals(LockState::LEVEL_NONE, LockState::getNextLevel(LockState::LOCKED), 'click on red -> unlock');
