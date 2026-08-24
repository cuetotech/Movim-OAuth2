<h1 align="center">Movim OAuth</h1>
<h3 align="center">An OAuth-focused fork of Movim</h3>

![build ci badge](https://github.com/cuetotech/movim-oauth/actions/workflows/main.yml/badge.svg?event=push)

Fork notice
-----------
This repository is an independently maintained fork of [Movim](https://github.com/movim/movim), adding OAuth-related deployment and authentication customizations. It may diverge from upstream and is not the official Movim project or affiliated with its maintainers.

About
-----
Movim is a federated blogging and chat platform that acts as a web frontend for the XMPP protocol. This fork retains Movim as its upstream project.

![movim screenshot](https://movim.eu/img/home.webp)

Deployment
----------
Please refer to the installation instructions in the [INSTALL.md](INSTALL.md) file, or check out the [Movim Wiki](https://github.com/movim/movim/wiki) for more information.

Quick Test
----------

You can try out Movim on your local machine in a container using [Podman (main website)](https://podman.io/). Podman is a FOSS alternative to Docker that is available on all the main distributions.

⚠️ __This setup is only for tests purpose, the containers are not optimized and most of the caches are disabled. To deploy your own Movim instance use the [INSTALL.md](INSTALL.md) tutorial.__

Install `podman-compose` and clone the repository before trying the next steps.

Launch the podman-compose script

    podman-compose up

After a few minutes it will launch a local test instance with a blank database.

You can then access in your browser at the following URL:

    https://127.0.0.1:8443/

The container is using a self-signed certificate, accept to get to the login page.

Security report
---------------

See [SECURITY.md](./SECURITY.md).

Support Us
----------
You can help Movim by:

* Doing a one time donation using PayPal [![Donate](https://img.shields.io/badge/Donate-PayPal-blue.svg)](https://www.paypal.com/cgi-bin/webscr?cmd=_donations&business=8QHPJDAQXT9UC)
* Helping us covering our monthly costs on our official Patreon page [![Donate](https://img.shields.io/badge/Patreon-Become%20a%20Patron-orange.svg)](https://www.patreon.com/movim)
* Helping us weekly on Liberapay [![Donate](https://img.shields.io/liberapay/goal/Movim?label=Liberapay&color=f6c915)](https://liberapay.com/movim)

Links
-----
* Movim official website: [movim.eu](https://movim.eu/)
* You can join one of the Movim instances on [join.movim.eu](https://join.movim.eu/)
* Mastodon: [@movim@piaille.fr](https://piaille.fr/@movim)
* XMPP Chatroom: [movim@conference.movim.eu](xmpp:movim@conference.movim.eu)

Translations
------------
Help us translate Movim on https://explore.transifex.com/movim/movim/

License
-------
Movim is released under the terms of the AGPLv3 license. See [COPYING.txt](./COPYING.txt) for more details.
