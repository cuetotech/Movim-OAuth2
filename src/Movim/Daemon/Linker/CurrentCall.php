<?php
/*
 * SPDX-FileCopyrightText: 2024 Jaussoin Timothée
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Movim\Daemon\Linker;

use App\User;
use Carbon\Carbon;
use Movim\Widget\Wrapper;
use Moxl\Xec\Payload\Packet;

/**
 * This class handle the current Jitsi call
 */
class CurrentCall
{
    public ?string $jid = null;
    public ?string $id = null;
    public ?string $mujiRoom = null;
    public ?Carbon $startTime = null;
    public ?string $pendingJid = null;
    public ?string $pendingId = null;

    public function __construct(private User $user, private string $sessionId)
    {
    }

    public function start(string $jid, string $id, ?string $mujiRoom = null): bool
    {
        if ($this->hasPending($id) && $this->isPendingJid($jid)) {
            $this->pendingJid = $this->pendingId = null;
        }

        if ($this->isStarted()) return false;

        $this->jid = $jid;
        $this->id = $id;
        $this->mujiRoom = $mujiRoom;
        $this->startTime = Carbon::now();

        Wrapper::getInstance()->iterate(
            key: 'currentcall_started',
            packet: (new Packet)->pack(['id' => $id, 'mujiroom' => $mujiRoom], $this->getBareJid()),
            user: $this->user,
            sessionId: $this->sessionId
        );

        return true;
    }

    public function stop(string $jid, string $id): bool
    {
        if ($this->getBareJid() != \bareJid($jid) || $this->id != $id) return false;

        $jid = $this->getBareJid();
        $id = $this->id;
        $mujiRoom = $this->mujiRoom;

        $this->jid = $this->id = $this->mujiRoom = $this->startTime = null;

        Wrapper::getInstance()->iterate(
            key: 'currentcall_stopped',
            packet: (new Packet)->pack(['id' => $id, 'mujiroom' => $mujiRoom], $jid),
            user: $this->user,
            sessionId: $this->sessionId
        );

        return true;
    }

    public function reserve(string $jid, string $id): bool
    {
        if ($this->isBusy()) {
            return false;
        }

        $this->pendingJid = $jid;
        $this->pendingId = $id;

        return true;
    }

    public function releasePending(string $jid, string $id): bool
    {
        if (!$this->hasPending($id) || !$this->isPendingJid($jid)) {
            return false;
        }

        $this->pendingJid = $this->pendingId = null;

        return true;
    }

    public function hasId(string $id): bool
    {
        return $this->id == $id;
    }

    public function hasPending(?string $id = null): bool
    {
        return $this->pendingJid != null
            && $this->pendingId != null
            && ($id == null || $this->pendingId == $id);
    }

    public function isJidInCall(string $jid): bool
    {
        return \bareJid($jid) == $this->getBareJid();
    }

    public function isPendingJid(string $jid): bool
    {
        return \bareJid($jid) == $this->getPendingBareJid();
    }

    public function isStarted(): bool
    {
        return $this->jid != null && $this->id != null;
    }

    public function isBusy(): bool
    {
        return $this->isStarted() || $this->hasPending();
    }

    public function getBareJid(): ?string
    {
        if (!$this->isStarted()) return null;

        return \bareJid($this->jid);
    }

    public function getPendingBareJid(): ?string
    {
        if (!$this->hasPending()) return null;

        return \bareJid($this->pendingJid);
    }
}
