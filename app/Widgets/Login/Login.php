<?php

namespace App\Widgets\Login;

use App\Services\OAuth\TokenIssuer;
use App\Services\OAuth\TrustedIdentityResolver;
use Moxl\Xec\Action\Storage\Get;
use Moxl\Xec\Payload\Packet;
use Moxl\Stanza\Stream;

use Respect\Validation\Validator;
use Defuse\Crypto\Key;
use Defuse\Crypto\Crypto;
use League\CommonMark\GithubFlavoredMarkdownConverter;

use App\Configuration;
use App\Session;
use App\User;
use App\Widgets\Presence\Presence;

use Movim\Widget\Base;
use Movim\Cookie;

class Login extends Base
{
    private ?array $trustedIdentity = null;

    public function load()
    {
        $this->addcss('login.css');
        $this->addjs('login.js');

        $this->registerEvent('session_start_handle', 'onStart'); // Bind 1
        $this->registerEvent('sasl2success', 'onStart'); // Bind 2 - SASL2
        $this->registerEvent('saslsuccess', 'onSASLSuccess');
        $this->registerEvent('saslfailure', 'onSASLFailure');
        $this->registerEvent('sasl2failure', 'onSASLFailure');
        $this->registerEvent('socket_connected', 'onConnected');
        $this->registerEvent('storage_get_handle', 'onConfig');
        $this->registerEvent('storage_get_error', 'onConfig');
        $this->registerEvent('ssl_error', 'onFailAuth');
        $this->registerEvent('dns_error', 'onDNSError');
        $this->registerEvent('connection_error', 'onConnectionError');
        $this->registerEvent('streamerror', 'onFailAuth');
    }

    public function onStart(Packet $packet)
    {
        $this->xmpp(new Get)->request();
    }

    public function onConnected()
    {
        $this->toast($this->__('connection.socket_connected'));
        $this->flushPendingStreamInit();
    }

    public function onSASLSuccess(Packet $packet)
    {
        $this->toast($this->__('connection.authenticated'));
    }

    public function onConfig(Packet $packet)
    {
        $p = new Presence(user: $this->me, sessionId: $this->sessionId);
        $p->start();

        $this->rpc('MovimUtils.reloadThis');
    }

    public function display()
    {
        $configuration = Configuration::get();
        $this->trustedIdentity = (new TrustedIdentityResolver)->resolve();

        if (!empty($configuration->info)) {
            $converter = new GithubFlavoredMarkdownConverter([
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
            ]);

            $this->view->assign('info', $converter->convert($configuration->info));
        }

        $this->view->assign('banner', $configuration->banner);
        $this->view->assign('whitelist', $configuration->xmppwhitelist);

        if (
            isset($configuration->xmppdomain)
            && !empty($configuration->xmppdomain)
        ) {
            $this->view->assign('domain', $configuration->xmppdomain);
        } else {
            $this->view->assign('domain', 'movim.eu');
        }

        $this->view->assign('invitation', null);

        if (
            $this->get('i')
            && Validator::length(8)->isValid($this->get('i'))
        ) {
            $invitation = \App\Invite::find($this->get('i'));

            if ($invitation) {
                $this->view->assign('invitation', $invitation);
                $this->view->assign('contact', \App\Contact::firstOrNew(['id' => $invitation->user_id]));
            }
        }

        $started = (int)requestAPI('started');

        $this->view->assign('pop', User::count());
        $this->view->assign('admins', User::where('admin', true)->get());
        $this->view->assign('connected', $started);
        $this->view->assign('maxsessions', $configuration->maxsessions);
        $this->view->assign('maxsessionsreached', ($configuration->maxsessions > 0 && $started >= $configuration->maxsessions));
        $this->view->assign('error', $this->prepareError());
        $this->view->assign('oauthEnabled', config('oauth.enabled'));
        $this->view->assign('oauthPasswordFallback', config('oauth.allow_password_fallback', true));
        $this->view->assign(
            'oauthAutoLogin',
            config('oauth.enabled')
            && config('oauth.auto_login', true)
            && $this->trustedIdentity !== null
        );

        if (
            isset($_SERVER['PHP_AUTH_USER'])
            && isset($_SERVER['PHP_AUTH_PW'])
            && Validator::email()->length(6, 40)->isValid($_SERVER['HTTP_EMAIL'])
        ) {
            list($username, $host) = explode('@', $_SERVER['HTTP_EMAIL']);
            $this->view->assign('httpAuthHost', $host);
            $this->view->assign('httpAuthUser', $_SERVER['HTTP_EMAIL']);
            $this->view->assign('httpAuthPassword', $_SERVER['PHP_AUTH_PW']);
        }
    }

    public function showErrorBlock($error, ?string $errorMessage = null)
    {
        $this->me?->encryptedPasswords()->delete();

        $this->rpc('Login.clearQuick');
        $this->rpc('MovimTpl.fill', '#error', $this->prepareError($error, $errorMessage));
        $this->rpc('MovimUtils.addClass', '#login_widget', 'error');
    }

    public function prepareError(string $error = 'default', ?string $errorMessage = null)
    {
        $view = $this->tpl();

        $key = 'error.' . $error;
        $error_text = $this->__($key);

        if ($error_text == $key) {
            $view->assign('error', $this->__('error.default'));
        } else {
            $view->assign('error', $error_text);
        }

        $view->assign('errormessage', $errorMessage);

        return $view->draw('_login_error');
    }

    public function onFailAuth()
    {
        $this->showErrorBlock('fail_auth');
    }

    public function onDNSError()
    {
        $this->showErrorBlock('dns');
    }

    public function onConnectionError(Packet $packet)
    {
        $this->showErrorBlock('connection', errorMessage: $packet->content);
    }

    public function onSASLFailure(Packet $packet)
    {
        switch ($packet->content) {
            case 'invalid-mechanism':
            case 'malformed-request':
                $error = 'mechanism';
                break;
            case 'not-authorized':
            case 'bad-auth':
                $error = 'wrong_account';
                break;
            case 'bad-protocol':
            default:
                $error = 'fail_auth';
                break;
        }

        $this->showErrorBlock($error);
    }

    public function ajaxLogin($form, string $sessionId, string $timezone)
    {
        if (config('oauth.enabled') && !config('oauth.allow_password_fallback', true)) {
            $this->showErrorBlock('oauth_only');
            return;
        }

        $username = strtolower($form->username->value);
        $password = $form->password->value;
        $this->doLogin($username, $password, $timezone, $sessionId);
    }

    public function ajaxHTTPLogin(string $login, string $password, string $sessionId, string $timezone)
    {
        if (config('oauth.enabled') && !config('oauth.allow_password_fallback', true)) {
            $this->showErrorBlock('oauth_only');
            return;
        }

        $this->doLogin($login, $password, $timezone, $sessionId);
    }

    public function ajaxHttpOAuthLogin(string $sessionId, string $timezone)
    {
        $trustedIdentity = (new TrustedIdentityResolver)->resolve();

        if ($trustedIdentity === null) {
            $this->showErrorBlock('oauth_identity');
            return;
        }

        if ($sessionId == null || strlen($sessionId) != 32) {
            $this->showErrorBlock('session');
            return;
        }

        $configuration = Configuration::get();
        $started = (int)requestAPI('started');

        if ($configuration->maxsessions > 0 && $started >= $configuration->maxsessions) {
            $this->showErrorBlock('max_sessions_reached');
            return;
        }

        $login = $trustedIdentity['jid'];
        [$username, $host] = explode('@', $login);

        if (
            !empty($configuration->xmppwhitelist)
            && !in_array($host, $configuration->xmppwhitelist)
        ) {
            $this->showErrorBlock('unauthorized');
            return;
        }

        $existing = \App\Session::where('username', $username)->where('host', $host)->first();

        if ($existing) {
            $process = (bool)requestAPI('exists', post: ['sid' => $existing->id]);

            if ($process) {
                $this->rpc('Login.setCookie', $existing->id, date(DATE_COOKIE, Cookie::getTime()));
                $this->rpc('MovimUtils.redirect', $this->route('main'));
                return;
            }

            $existing->delete();
        }

        if ($stale = \App\Session::find($sessionId)) {
            $stale->delete();
        }

        try {
            $tokenResponse = (new TokenIssuer)->issueToken(
                $trustedIdentity['raw_identity'],
                $login
            );
        } catch (\Throwable $e) {
            logError($e);
            $this->showErrorBlock('oauth_broker');
            return;
        }

        $token = $tokenResponse['access_token'];

        $user = User::firstOrNew(['id' => $login]);
        $user->init();
        $user->save();

        $session = new \App\Session;
        $session->init(
            username: $username,
            password: generateKey(64),
            host: $host,
            sessionId: $sessionId,
            timezone: $timezone
        );
        $session->save();

        $payload = json_encode([
            'func' => 'message',
            'b' => [
                'w' => 'Login',
                'f' => 'ajaxOAuthDaemonLogin',
                'p' => [$login, $token, $timezone],
            ],
        ]);

        $dispatched = requestAPI('ajax', post: [
            'sid' => $sessionId,
            'json' => rawurlencode($payload),
        ]);

        if ($dispatched === false) {
            $session->delete();
            $this->showErrorBlock('connection');
        }
    }

    public function ajaxOAuthDaemonLogin(string $login, string $token, string $timezone)
    {
        if (!validateJid($login) || !Validator::stringType()->length(1, 4096)->isValid($token)) {
            $this->showErrorBlock('oauth_broker');
            return;
        }

        $user = User::where('id', $login)->first();
        $linker = linker($this->sessionId);

        if (!$user || $linker == null) {
            $this->showErrorBlock('oauth_identity');
            return;
        }

        [$username, $host] = explode('@', $login);

        $linker->attachUser($user);
        $linker->authentication->username = $username;
        $linker->authentication->jid = $login;
        $linker->authentication->token = $token;
        $linker->timezone = $timezone;

        $this->queueXmppConnection($login, $host);
    }

    public function ajaxQuickLogin(
        string $deviceId,
        string $login,
        string $key,
        string $timezone,
        ?string $sessionId = null,
        ?bool $check = false
    ) {
        if ($sessionId == null) return;

        if (config('oauth.enabled') && !config('oauth.allow_password_fallback', true)) {
            $this->showErrorBlock('oauth_only');
            return;
        }

        if (!validateJid($login)) {
            $this->showErrorBlock('login_format');
            return;
        }

        try {
            $key = Key::loadFromAsciiSafeString($key);
            $user = \App\User::find($login);

            if ($user) {
                $ciphertext = $user->encryptedPasswords()->find($deviceId);

                if ($ciphertext) {
                    if ($check) {
                        $this->rpc('Login.quickLoginRegister');
                        return;
                    }

                    $ciphertext->touch();
                    $password = Crypto::decrypt($ciphertext->data, $key);

                    $this->doLogin(
                        login: $login,
                        password: $password,
                        sessionId: $sessionId,
                        timezone: $timezone,
                        deviceId: $deviceId
                    );
                } else {
                    $this->rpc('Login.clearQuick');
                }
            }
        } catch (\Exception $e) {
            $this->rpc('Login.clearQuick');
        }
    }

    private function doLogin(
        string $login,
        string $password,
        string $timezone,
        ?string $sessionId = null,
        ?string $deviceId = null
    ) {
        $configuration = Configuration::get();

        if (!validateJid($login)) {
            $this->showErrorBlock('login_format');
            return;
        }

        if (!Validator::stringType()->length(1, 128)->isValid($password)) {
            $this->showErrorBlock('password_format');
            return;
        }

        if ($sessionId != null && strlen($sessionId) != 32) {
            $this->showErrorBlock('password_format');
            return;
        }

        $started = (int)requestAPI('started');
        if ($configuration->maxsessions > 0 && $started >= $configuration->maxsessions) {
            $this->showErrorBlock('max_sessions_reached');
            return;
        }

        list($username, $host) = explode('@', $login);

        if (
            !empty($configuration->xmppwhitelist)
            && !in_array($host, $configuration->xmppwhitelist)
        ) {
            $this->showErrorBlock('unauthorized');
            return;
        }

        // We check if we already have an open session
        $here = \App\Session::where('username', $username)->where('host', $host)->first();

        $user = User::firstOrNew(['id' => $login]);
        $user->init();
        $user->save();

        if ($deviceId == null) {
            $rkey = Key::createNewRandomKey();
            $deviceId = generateKey();

            $key = new \App\EncryptedPassword;
            $key->user_id = $login;
            $key->id = $deviceId;
            $key->data = Crypto::encrypt($password, $rkey);
            $key->save();

            $this->rpc('Login.setQuick', $deviceId, $login, $host, $rkey->saveToAsciiSafeString());
        }

        if ($here && password_verify(Session::hashSession($username, $password, $host), $here->hash)) {
            $this->rpc('Login.setCookie', $here->id, date(DATE_COOKIE, Cookie::getTime()));
            $this->rpc('MovimUtils.redirect', $this->route('main'));
            return;
        } elseif (\App\Session::where('username', $username)->where('host', $host)->exists()) {
            $this->showErrorBlock('wrong_account');
            return;
        }

        $s = new \App\Session;
        $s->init(
            username: $username,
            password: $password,
            host: $host,
            sessionId: $sessionId,
            timezone: $timezone
        );
        $s->save();

        $linker = linker($s->id);

        if ($linker == null) {
            $s->delete();
            $this->showErrorBlock('connection');
            return;
        }

        $linker->attachUser(User::where('id', $login)->first());
        $linker->authentication->username = $username;
        $linker->authentication->password = $password;
        $linker->authentication->jid = $login;
        $linker->timezone = $timezone;

        $this->queueXmppConnection($login, $host);
    }

    private function queueXmppConnection(string $login, string $host): void
    {
        $linker = linker($this->sessionId);

        if ($linker == null) {
            return;
        }

        if (!$linker->session->get('host')) {
            $linker->session->set('host', $host);
            $linker->register($host);
        }

        $linker->session->set('pending_stream_init', [
            'host' => $host,
            'login' => $login,
        ]);

        $this->flushPendingStreamInit();
    }

    private function flushPendingStreamInit(): void
    {
        $linker = linker($this->sessionId);

        if ($linker == null || !$linker->connected()) {
            return;
        }

        $pending = $linker->session->get('pending_stream_init');

        if (!is_array($pending) || empty($pending['host']) || empty($pending['login'])) {
            return;
        }

        $linker->writeXMPP(Stream::init($pending['host'], $pending['login']));
        $linker->session->delete('pending_stream_init');
    }
}
