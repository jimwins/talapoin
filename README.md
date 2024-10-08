# [talapoin](https://trainedmonkey.com/projects/talapoin/)
A small blogging/CMS system, used on trainedmonkey.com

Talapoin uses:

- [Slim][slim]: a micro framework for PHP
- [Twig][twig]: The flexible, fast, and secure template engine for PHP
- [Phinx][phinx]: PHP database migrations for everyone
- [SHJS][shjs]: Syntax Highlighting in JavaScript
- [Awesomeplete][awesomeplete]: Simple autocomplete widget

## How to use

The repository includes a way to run a development version within Docker
and using Tailscale to provide access. It's driven using a `Makefile`, so
you can run:

```sh
$ make up
```

It will tell you that you need to create the `.env` and `config.ini` files if
they do not already exist. See `sample.env` and `config.ini.sample` for
examples.

## About the talapoin

You can find information about the talapoin, a small, yellow Central African
monkey, here:

http://www.factzoo.com/mammals/talapoin-small-yellow-central-african-monkey.html

## License

See the LICENSE file for licensing information. Please note that this license
may be more permissive than some of the supporting libraries used.

[Jim Winstead](mailto:jimw@trainedmonkey.com), August 2018  
https://trainedmonkey.com/projects/talapoin/

[slim]: https://www.slimframework.com
[twig]: https://twig.symfony.com/
[phinx]: https://phinx.org
[shjs]: http://shjs.sourceforge.net
[awesomeplete]: https://projects.verou.me/awesomplete/
