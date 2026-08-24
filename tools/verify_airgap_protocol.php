<?php

/**
 * Offline smoke test for the protocol-sensitive airgap patches.
 *
 * Usage:
 *   php tools/verify_airgap_protocol.php
 *
 * This intentionally needs no network access and only exercises stanza
 * generation. Runtime interoperability still requires the acceptance tests in
 * docs/airgap-patch-protocol-matrix.md.
 */

require_once dirname(__DIR__) . '/src/Moxl/Stanza/Jingle.php';

use Moxl\Stanza\Jingle;

const JMI_NS = 'urn:xmpp:jingle-message:0';
const JINGLE_NS = 'urn:xmpp:jingle:1';
const HINTS_NS = 'urn:xmpp:hints';

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function check(bool $condition, string $message): void
{
    if (!$condition) fail($message);
}

function xpath(\DOMDocument $dom): \DOMXPath
{
    $xp = new \DOMXPath($dom);
    $xp->registerNamespace('c', 'jabber:client');
    $xp->registerNamespace('jmi', JMI_NS);
    $xp->registerNamespace('jingle', JINGLE_NS);
    $xp->registerNamespace('hints', HINTS_NS);
    return $xp;
}

function assertJmiEnvelope(\DOMDocument $dom, string $element, string $id): \DOMXPath
{
    $xp = xpath($dom);
    $message = $dom->documentElement;
    check($message !== null && $message->localName === 'message', "{$element}: root must be message");
    check($message->getAttribute('type') === 'chat', "{$element}: message type must be chat");
    check($xp->query("/c:message/jmi:{$element}[@id='{$id}']")->length === 1, "{$element}: missing JMI element or ID");
    check($xp->query('/c:message/hints:store')->length === 1, "{$element}: missing XEP-0334 store hint");
    return $xp;
}

$id = 'ca3cf894-5325-482f-a412-a6e9f832298d';
$other = 'fecbea35-08d3-404f-9ec7-2b57c566fa74';

$propose = Jingle::messagePropose('juliet@example.test', $id, true);
$xp = assertJmiEnvelope($propose, 'propose', $id);
check($xp->query('/c:message/jmi:propose/*[local-name()="description" and @media="audio"]')->length === 1, 'propose: audio description missing');
check($xp->query('/c:message/jmi:propose/*[local-name()="description" and @media="video"]')->length === 1, 'propose: video description missing');

$ringing = Jingle::messageRinging('romeo@example.test/orchard', $id);
assertJmiEnvelope($ringing, 'ringing', $id);
check($ringing->documentElement->getAttribute('to') === 'romeo@example.test/orchard', 'ringing: full JID target not preserved');

$proceed = Jingle::messageProceed('romeo@example.test/orchard', $id);
assertJmiEnvelope($proceed, 'proceed', $id);
check($proceed->documentElement->getAttribute('to') === 'romeo@example.test/orchard', 'proceed: full JID target not preserved');

$busy = Jingle::messageReject($id, 'romeo@example.test/orchard', 'busy', 'Busy', false);
$xp = assertJmiEnvelope($busy, 'reject', $id);
check($xp->query('/c:message/jmi:reject/jingle:reason/jingle:busy')->length === 1, 'reject: busy reason missing');

$tieReject = Jingle::messageReject($other, 'romeo@example.test/orchard', 'expired', 'Tie-Break', true);
$xp = assertJmiEnvelope($tieReject, 'reject', $other);
check($xp->query('/c:message/jmi:reject/jingle:reason/jingle:expired')->length === 1, 'tie reject: expired reason missing');
check($xp->query('/c:message/jmi:reject/jmi:tie-break')->length === 1, 'tie reject: tie-break missing');

$tieRetract = Jingle::messageRetract('juliet@example.test', $other, 'expired', 'Tie-Break', true);
$xp = assertJmiEnvelope($tieRetract, 'retract', $other);
check($xp->query('/c:message/jmi:retract/jingle:reason/jingle:expired')->length === 1, 'tie retract: expired reason missing');
check($xp->query('/c:message/jmi:retract/jmi:tie-break')->length === 1, 'tie retract: tie-break missing');

$finish = Jingle::messageFinish('juliet@example.test/phone', $id, 'success');
$xp = assertJmiEnvelope($finish, 'finish', $id);
check($finish->documentElement->getAttribute('to') === 'juliet@example.test/phone', 'finish: full JID target not preserved');
check($xp->query('/c:message/jmi:finish/jingle:reason/jingle:success')->length === 1, 'finish: success reason missing');

// i;octet is bytewise lexical ordering. UUID session IDs are ASCII, so strcmp
// is the intended comparison primitive used by JinglePropose for §4.1.
check(strcmp($id, $other) < 0, 'tie-break fixture ordering is invalid');

fwrite(STDOUT, "PASS: airgap JMI stanza smoke tests\n");
