<h1 align="center">Movim OAuth</h1>
<h3 align="center">An OAuth-focused fork of Movim</h3>

![build ci badge](https://github.com/cuetotech/Movim-OAuth2/actions/workflows/main.yml/badge.svg?event=push)

Fork notice
-----------
This repository is an independently maintained fork of [Movim](https://github.com/movim/movim), adding OAuth-related deployment and authentication customizations. It may diverge from upstream and is not the official Movim project or affiliated with its maintainers.

About
-----
Movim is a federated blogging and chat platform that acts as a web frontend for the XMPP protocol. This fork retains Movim as its upstream project.

![movim screenshot](https://movim.eu/img/home.webp)

Deployment
----------
Please refer to the installation instructions in the [INSTALL.md](INSTALL.md) file, or check out the [Movim Wiki](https://github.com/movim/movim/wiki) for general Movim information.

OAuth-specific deployment and token-service documentation is available under [`docs/`](docs/), including Apache, Nginx, trusted-identity, and remote ejabberd deployment examples.

Quick Test
----------

You can try out Movim on your local machine in a container using [Podman](https://podman.io/). This setup is intended for testing; use the deployment documentation for production installs.

Install `podman-compose` and clone the repository, then run:

    podman-compose up

After a few minutes a local test instance will be available at:

    https://127.0.0.1:8443/

Security report
---------------

See [SECURITY.md](./SECURITY.md). Security issues in the OAuth fork should be reported to this fork's maintainer; upstream Movim issues should be reported to the upstream project.

Upstream project
----------------
* Movim official website: [movim.eu](https://movim.eu/)
* Upstream repository: [movim/movim](https://github.com/movim/movim)
* Join a Movim instance: [join.movim.eu](https://join.movim.eu/)
* XMPP Chatroom: [movim@conference.movim.eu](xmpp:movim@conference.movim.eu)

License
-------
Movim is released under the terms of the AGPLv3 license. See [COPYING.txt](./COPYING.txt) for more details.
