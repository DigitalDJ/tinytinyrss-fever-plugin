# Tiny Tiny RSS Fever API Plugin

## Description

This is an open source plugin for Tiny Tiny RSS which simulates the Fever API. This allows Fever compatible RSS clients to use Tiny Tiny RSS.

See also: [Fever API](fever-api.md)

- - -

* <a href="#features">Features</a>
* <a href="#download">Downloads</a>
* <a href="#supported">Supported / Tested Clients</a>
* <a href="#installation">Installation</a>
* <a href="#upgrading">Upgrading</a>
* <a href="#debug">Debugging</a>
* <a href="#error">Error Reporting</a>
* <a href="#license">License</a>
* <a href="#changelog">Change Log</a>

## <a name="features">Features</a>

Following Features are implemented:

* getting new RSS items
* getting starred RSS items
* setting read marker for item(s)
* setting starred marker for item(s)
* hot links

## <a name="downloads">Downloads</a>
Like Tiny Tiny RSS, the Fever API plugin is a rolling release model and there are no periodic updates. You should use git to clone the repository to install the plugin. If you must manually download a snapshot of the master branch, you can click the [`Download ZIP`](https://github.com/DigitalDJ/tinytinyrss-fever-plugin/archive/master.zip) button.

## <a name="supported">Supported / Tested Clients</a>

These clients should work with Fever API emulation.

* [Reeder](http://reederapp.com) - iPhone
* [Mr.Reader](https://www.curioustimes.de/mrreader/index.html) - iPad
* [ReadKit](http://readkitapp.com) - OS X
* [Press](https://play.google.com/store/apps/details?id=com.twentyfivesquares.press) - Android
* [Meltdown](https://github.com/phubbard/Meltdown) - Android
  * displays feeds as 'orphan' items, but runs fine

## <a name="installation">Installation</a>

**IMPORTANT** You must enable the option `Enable API access` in your Tiny Tiny RSS preferences, for every user that wants to use the Fever plugin.

Clone this repository to your `plugins.local` folder of your Tiny Tiny RSS installation.

```
$ cd tt-rss/plugins.local
$ git clone https://github.com/DigitalDJ/tinytinyrss-fever-plugin fever
```

Enable the `fever` plugin in the Tiny Tiny RSS Preferences and reload.

A `Fever Emulation` accordion pane should appear in your Tiny Tiny RSS preferences that will allow you to set a password for the Fever API. This is the password you will use to login to your Fever client, and should be different to your Tiny Tiny RSS login password.

**IMPORTANT** The Fever API uses insecure unsalted MD5 hash. You should choose a disposable application-specific password and consider the use of HTTPS with your Tiny Tiny RSS installation. [Let's Encrypt](https://letsencrypt.org/) is an excellent resource to setup free SSL certificates for your HTTP server.

Once the password is saved, you may login to your Fever client using your Tiny Tiny RSS username, the password you set in the previous step and the following server / endpoint URL:

```
https://example.com/tt-rss/plugins.local/fever/
```

See the [archived forum post](https://tt-rss.org/forum/viewtopic.php?f=22&t=1981) for more detailed and outdated information.

## <a name="upgrading">Upgrading</a>

Upgrading the Fever plugin follows the same steps as your Tiny Tiny RSS installation:

```
$ cd tt-rss/plugins.local
$ git pull origin master
```

## <a name="debug">Debugging</a>

In the file ```fever_api.php``` there are two flags for debugging at the beginning of the file.

* ```DEBUG``` - set this to `TRUE` to produce extra debugging output. The location of the log is dependent on your PHP `log_errors` and `error_log` configuration directives.
* ```DEBUG_USER``` - set this to the ID (from `ttrss_users`  database table) of your user you would like to force authenticate with. The authentication process is then skipped and the API is always authenticated using this ID.

## <a name="error">Error Reporting</a>

If you have problems with authentication after updating the plugin, try to re-enter the password in Tiny Tiny RSS Fever plugin and save it again.

If you encounter any defects please [create an issue](https://github.com/DigitalDJ/tinytinyrss-fever-plugin/issues/new) on GitHub.

Please include any debug logs and any output from the Tiny Tiny RSS `Error Log` (located in Preferences > System).

**IMPORTANT** Ensure logs are sanitized by removing any usernames, passwords and API keys.

Also specify versions and variants of the software you are using:
* Tiny Tiny RSS commit
* PHP (and integration with your HTTP server, e.g. php-fpm)
* Operating System (e.g. FreeBSD, Debian)
* HTTP Server (e.g. Apache, nginx)
* Database Server (e.g. MySQL, PostgreSQL)

## <a name="license">License</a>

GPL-3.0

## <a name="changelog">Change Log</a>

v1.0-v1.2 - 2013/05/27

* see this [thread](https://tt-rss.org/forum/viewtopic.php?f=22&t=1981) in the Tiny Tiny RSS Forum

v1.3 - 2013/06/27

* fixed several bugs in json output from the plugin
* added a small fix for Mr.Reader 2.0 so it can complete loading of all items (see [FAQ](http://www.curioustimes.de/mrreader/faq/))
* added first Mr.Reader compatiblity without marking items read/starred
* changed the field date_entered to updated for better reading experience

v1.4 - 2013/06/28

* fixed authentication with Mr.Reader 2.0
* fixed debugging options

v1.4.1 - 2013/06/28

* removed password from debug log file

v1.4.2 - 2013/06/28

* changed the DEBUG_USER evaluation a little bit for disabling authentication without DEBUG = true

v1.4.3 - 2013/06/28

* added DEBUG_FILE to debug configuration
* changed authentication call from Mr.Reader so that the reply is also uppercase, since the API-KEY comes in uppercase from clients
* fixed debug output while authentication in Mr.Reader with displaying the email adress

v1.4.4 - 2013/06/28

* updated the documentation
* changed some in saving the generated API-KEY - now its generated like in the Fever API documentation

v1.4.5 - 2013/06/29

* fixed the cannot mark/star bug in Mr.Reader

v1.4.6 - 2014/01/15

* merged bigger pull request to get more Fever API RSS Readers to work

v1.4.7 - 2014/01/15

* added rewrite url function to module, since it was removed from Tiny Tiny RSS

v2.0 - 2017/05/16

* Fix ccache exceptions
* Sync previously copied snipets with latest tt-rss source
* General clean up / refactor
* Replace clunky sanitization with what is provided by tt-rss
* Use new Article class for enclosures

v2.1 - 2017/12/25

* Sync previously copied snipets with latest tt-rss source
* Use PDO API for DB queries

v2.2 - 2018/01/22

* Fix finding config.php for obscure tt-rss installations
* Use PDO query for saving passwords
* Fix PHP5 only having single unserialize argument

v2.3 - 2020/01/27

* Fix error thrown when str_repeat() is passed negative length
* Removes references to CCache class which has been scrapped and replaced
