<?php

namespace Moxl\Xec\Action\Jingle;

use App\Message;
use Moxl\Xec\Action;
use Moxl\Stanza\Jingle;

class MessageReject extends Action
{
    protected $_to;
    protected $_id;
    protected string $_reason = 'busy';
    protected ?string $_text = 'Busy';
    protected bool $_tiebreak = false;

    public function request()
    {
        $this->store();
        $this->send(Jingle::messageReject(
            (string)$this->_id,
            $this->_to,
            $this->_reason,
            $this->_text,
            $this->_tiebreak
        ));

        $message = Message::eventMessageFactory(
            $this->me,
            'jingle',
            bareJid($this->_to),
            (string)$this->_id
        );
        $message->type = 'jingle_reject';
        $message->save();

        $this->pack($message);
        $this->event('jingle_message');
    }
}
