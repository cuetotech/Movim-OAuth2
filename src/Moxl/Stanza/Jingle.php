<?php

namespace Moxl\Stanza;

class Jingle
{
    private static function appendStoreHint(\DOMDocument $dom, \DOMElement $message): void
    {
        $message->appendChild($dom->createElementNS('urn:xmpp:hints', 'store'));
    }

    private static function appendReason(
        \DOMDocument $dom,
        \DOMElement $parent,
        string $condition,
        ?string $text = null
    ): void {
        $reason = $dom->createElementNS('urn:xmpp:jingle:1', 'reason');
        $reason->appendChild($dom->createElementNS('urn:xmpp:jingle:1', $condition));

        if ($text !== null && $text !== '') {
            $reason->appendChild($dom->createElementNS('urn:xmpp:jingle:1', 'text', $text));
        }

        $parent->appendChild($reason);
    }

    private static function appendTieBreak(\DOMDocument $dom, \DOMElement $parent, bool $tieBreak): void
    {
        if ($tieBreak) {
            $parent->appendChild($dom->createElementNS('urn:xmpp:jingle-message:0', 'tie-break'));
        }
    }

    /**
     * XEP-0353: Jingle Message Initiation
     */
    public static function messagePropose(string $to, string $id, bool $withVideo = false)
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $message = $dom->createElementNS('jabber:client', 'message');
        $message->setAttribute('type', 'chat');
        $message->setAttribute('to', $to);
        $dom->appendChild($message);

        $propose = $dom->createElementNS('urn:xmpp:jingle-message:0', 'propose');
        $propose->setAttribute('id', $id);
        $message->appendChild($propose);

        if ($withVideo) {
            $description = $dom->createElementNS('urn:xmpp:jingle:apps:rtp:1', 'description');
            $description->setAttribute('media', 'video');
            $propose->appendChild($description);
        }

        $description = $dom->createElementNS('urn:xmpp:jingle:apps:rtp:1', 'description');
        $description->setAttribute('media', 'audio');
        $propose->appendChild($description);

        self::appendStoreHint($dom, $message);
        return $dom;
    }

    // Deprecated by the current XEP-0353 flow, retained for interoperability.
    public static function messageAccept(string $id)
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $message = $dom->createElementNS('jabber:client', 'message');
        $message->setAttribute('type', 'chat');
        $dom->appendChild($message);

        $accept = $dom->createElementNS('urn:xmpp:jingle-message:0', 'accept');
        $accept->setAttribute('id', $id);
        $message->appendChild($accept);

        self::appendStoreHint($dom, $message);
        return $dom;
    }

    public static function messageRinging(string $to, string $id)
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $message = $dom->createElementNS('jabber:client', 'message');
        $message->setAttribute('type', 'chat');
        $message->setAttribute('to', $to);
        $dom->appendChild($message);

        $ringing = $dom->createElementNS('urn:xmpp:jingle-message:0', 'ringing');
        $ringing->setAttribute('id', $id);
        $message->appendChild($ringing);

        self::appendStoreHint($dom, $message);
        return $dom;
    }

    public static function messageProceed(string $to, string $id)
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $message = $dom->createElementNS('jabber:client', 'message');
        $message->setAttribute('type', 'chat');
        $message->setAttribute('to', $to);
        $dom->appendChild($message);

        $proceed = $dom->createElementNS('urn:xmpp:jingle-message:0', 'proceed');
        $proceed->setAttribute('id', $id);
        $message->appendChild($proceed);

        self::appendStoreHint($dom, $message);
        return $dom;
    }

    public static function messageRetract(
        string $to,
        string $id,
        string $reason = 'cancel',
        ?string $text = 'Retracted',
        bool $tieBreak = false
    ) {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $message = $dom->createElementNS('jabber:client', 'message');
        $message->setAttribute('type', 'chat');
        $message->setAttribute('to', $to);
        $dom->appendChild($message);

        $retract = $dom->createElementNS('urn:xmpp:jingle-message:0', 'retract');
        $retract->setAttribute('id', $id);
        $message->appendChild($retract);

        self::appendReason($dom, $retract, $reason, $text);
        self::appendTieBreak($dom, $retract, $tieBreak);
        self::appendStoreHint($dom, $message);

        return $dom;
    }

    public static function messageFinish(string $to, string $id, string $reason)
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $message = $dom->createElementNS('jabber:client', 'message');
        $message->setAttribute('type', 'chat');
        $message->setAttribute('to', $to);
        $dom->appendChild($message);

        $finish = $dom->createElementNS('urn:xmpp:jingle-message:0', 'finish');
        $finish->setAttribute('id', $id);
        $message->appendChild($finish);

        self::appendReason($dom, $finish, $reason, ucfirst(str_replace('-', ' ', $reason)));
        self::appendStoreHint($dom, $message);

        return $dom;
    }

    public static function messageReject(
        string $id,
        string|false $to = false,
        string $reason = 'busy',
        ?string $text = 'Busy',
        bool $tieBreak = false
    ) {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $message = $dom->createElementNS('jabber:client', 'message');
        $message->setAttribute('type', 'chat');
        if ($to) {
            $message->setAttribute('to', $to);
        }
        $dom->appendChild($message);

        $reject = $dom->createElementNS('urn:xmpp:jingle-message:0', 'reject');
        $reject->setAttribute('id', $id);
        $message->appendChild($reject);

        self::appendReason($dom, $reject, $reason, $text);
        self::appendTieBreak($dom, $reject, $tieBreak);
        self::appendStoreHint($dom, $message);

        return $dom;
    }

    public static function sessionTerminate($sid, $value)
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $jingle = $dom->createElementNS('urn:xmpp:jingle:1', 'jingle');
        $jingle->setAttribute('action', 'session-terminate');
        $jingle->setAttribute('sid', $sid);

        $reason = $dom->createElement('reason');
        $jingle->appendChild($reason);

        $item = $dom->createElement($value);
        $reason->appendChild($item);

        return $jingle;
    }

    public static function sessionMute($sid, $name = false)
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $jingle = $dom->createElementNS('urn:xmpp:jingle:1', 'jingle');
        $jingle->setAttribute('action', 'session-info');
        $jingle->setAttribute('sid', $sid);

        $mute = $dom->createElement('mute');
        $mute->setAttribute('xmlns', 'urn:xmpp:jingle:apps:rtp:info:1');

        if ($name) {
            $mute->setAttribute('name', substr($name, 3));
        }

        $jingle->appendChild($mute);

        return $jingle;
    }

    public static function sessionUnmute($sid, $name = false)
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $jingle = $dom->createElementNS('urn:xmpp:jingle:1', 'jingle');
        $jingle->setAttribute('action', 'session-info');
        $jingle->setAttribute('sid', $sid);

        $mute = $dom->createElement('unmute');
        $mute->setAttribute('xmlns', 'urn:xmpp:jingle:apps:rtp:info:1');

        if ($name) {
            $mute->setAttribute('name', substr($name, 3));
        }

        $jingle->appendChild($mute);

        return $jingle;
    }
}
