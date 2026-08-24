<?php

namespace Moxl\Xec\Action\Jingle;

use Moxl\Xec\Action;
use Moxl\Stanza\Jingle;

class MessageRetract extends Action
{
    protected $_to;
    protected $_id;
    protected string $_reason = 'cancel';
    protected ?string $_text = 'Retracted';
    protected bool $_tiebreak = false;

    public function request()
    {
        $this->store();
        $this->send(Jingle::messageRetract(
            (string)$this->_to,
            (string)$this->_id,
            $this->_reason,
            $this->_text,
            $this->_tiebreak
        ));
    }
}
