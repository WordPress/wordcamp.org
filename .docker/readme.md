## Initial Setup

Follow these steps to setup a local WordCamp.org environment using [Docker](https://www.docker.com/). _Assume all command blocks start in the root directory (wordcamp.test) of the project._

1. Make sure you have Docker installed and running on your system.

1. Clone the repo:
    ```bash
    git clone git@github.com:WordPress/wordcamp.org.git wordcamp.test
    cd wordcamp.test
    ```

1. Generate and trust the SSL certificates, so you get a green bar and can adequately test service workers.
	```bash
	cd .docker
	brew install mkcert
	brew install nss
	mkcert -install
	mkcert -cert-file wordcamp.test.pem -key-file wordcamp.test.key.pem wordcamp.test *.wordcamp.test buddycamp.test *.buddycamp.test
	```
	
	_Using zsh? You may see `zsh: no matches found: *.wordcamp.test` running the final cert command above. Try prefixing the final command with `noglob`, i.e. `noglob mkcert -cert-file ...`_

1. Clone WordPress into the **public_html/mu** directory and check out the latest version's branch.
    ```bash
    cd public_html
    git clone git://core.git.wordpress.org/ mu
    cd mu
    git checkout 5.4
    ```

1. Install 3rd-party PHP packages used on WordCamp.org. For this, you must have [Composer](https://getcomposer.org/doc/00-intro.md) installed. Once it is, change back to the root directory of the project where the main **composer.json** file is located. (Not the one in .docker/config.)
	```bash
	composer install
	```

1. Install 3rd-party JS packages and build the CSS & JS needed for some projects. You'll need [node](https://nodejs.org/) & [yarn](https://yarnpkg.com/). Optionally you can use [nvm](https://github.com/nvm-sh/nvm) to keep your node version up to date. Running the following will install and build all of the projects in one step (omit `nvm` command if you're not using it).
    ```bash
    nvm install && nvm use
    yarn
    yarn workspaces run build
    ```

1. Build and boot the Docker environment.
    ```bash
    docker-compose up --build
	```

    This will provision the Docker containers and install 3rd-party plugins and themes used on WordCamp.org, if necessary. It could take some time depending upon the speed of your Internet connection. At the end of the process, you should see a message like this:

    ```bash
    wordcamp.test_1  | NOTICE: ready to handle connections
    ```
   
    In this case the Docker environment will be running in the foreground. To stop the environment, use `CTRL + c`.
    
    On subsequent uses, you can start the already-built environment up in the background, thus allowing other commands to be issued while the environment is still running:
    
    ```bash
    docker-compose up -d
    ```

	_Note: This will create `.docker/database` directory which will contain MySQL files to persist data across docker restarts._

    _Note: You won't be able to test in your browser just yet, so continue with the next steps._

1. Add all the sites listed to your hosts file.
    To get the list of sites, run the following command, and then remove the `http(s)://` prefix and `/` suffix.

    ```bash
    docker-compose exec wordcamp.test wp site list --field=url --allow-root
    ```

    Example hosts file entry:
    ```bash
    127.0.0.1 wordcamp.test central.wordcamp.test 2014.seattle.wordcamp.test 2020.shinynew.wordcamp.test buddycamp.test 2015.brighton.buddycamp.test
    ```

1. `/wp-admin` pages for these sites should now be accessible. Use `admin` as username and `password` as password to login. Front end pages will not be accessible until you complete the remaining steps.

	If your browser warns you about the self-signed certificates, then the CA certificate is not properly installed. For Chrome, [manually add the CA cert to Keychain Access](https://deliciousbrains.com/ssl-certificate-authority-for-local-https-development/). For Firefox, import it to `Preferences > Certificates > Advanced > Authorities`.

1. By default, docker will start with data defined in `.docker/wordcamp_dev.sql` and changes to data will be persisted across runs in `.docker/database`. To start with different database, delete `.docker/database` directory and replace the `.docker/wordcamp_dev.sql` file and run `docker-compose up --build -d` again.

1. Optional: Install Git hooks to automate code inspections during pre-commit:
    ```bash
    rm -rf .git/hooks
    ln -s .githooks .git/hooks
    ```


## Local Environment Customizations

You may have a need to change a configuration or behavior in the local environment without modifying files that are tracked by version control. For this, you can add a file to the **mu-plugins** directory called **sandbox-functionality.php**. This file is ignored by git, so changes made to it will not affect the state of the working directory.


## Useful Docker Commands:

Note: All of these commands are meant to be executed from project directory.

1. To start WordCamp docker containers, use:
    ```bash
    docker-compose up -d
    ```

    The `-d` flag directs Docker to run in background.

1. To stop the running the Docker containers, use:
    ```bash
    docker-compose stop
    ```
   
   Note that using `docker-compose down` instead will cause the re-provisioning of 3rd-party plugins and themes the next time the containers are started up.

1. To open a shell inside the web container, use:
    ```bash
    docker-compose exec wordcamp.test bash
    ```

    `wordcamp.test` is the name of docker service running `nginx` and `php`. `bash` is the name of command that we want to execute. This particular command will give us shell access inside the Docker.

    Similarly, for the MySQL container, you can use:

    ```bash
    docker-compose exec wordcamp.db bash
    ```

    `wordcamp.db` is the name of docker service running MySQL server.

1. To view `nginx` and `php-logs` use:
    ```bash
    docker-compose logs -f --tail=100 wordcamp.test
    ```

    The `-f` flag is used for consistently following the logs. Omit this to only dump the logs in terminal.

    The `--tail=100` flag is used to view only last `100` lines of log. Omit this if you'd like to see the entire log since docker started.

    `wordcamp.test` is the name of the Docker service which is running `nginx` and `php`

    Similarly, to view MySQL server logs, use:

    ```bash
    docker-compose logs -f --tail=100 wordcamp.db
    ```

    Note that this does not show MySQL queries made by application, these are just server logs.

    `wordcamp.db` is the name of Docker service which is running MySQL server.


Once the Docker instance has started, you can visit [2014.seattle.wordcamp.test](https://2014.seattle.wordcamp.test) to view a sample WordCamp site. WordCamp central would be [central.wordcamp.test](https://central.wordcamp.test). You can also visit [localhost:1080](localhost:1080) to view the MailCatcher dashboard.


## Testing with PHPUnit

We have separate containers for PHPUnit, a web server & database, to keep the tests isolated. To run the unit tests, follow these steps:

1. Start up the container:
    ```bash
    docker-compose -f docker-compose.phpunit.yml up
    ```

    Watch for the following output, to ensure that the server and database are finished starting up.
    ```
    phpunit_wp_1  | […] NOTICE: ready to handle connections
    …
    phpunit_db_1  | […] [Note] mysqld: ready for connections.
    ```

2. The first time you run this, you'll need to install the tests (future runs can skip this step). First, open a shell inside the web container:
    ```bash
    docker-compose -f docker-compose.phpunit.yml exec phpunit_wp bash
    ```

    Then run the install script. It will download WordPress & the unit test framework (this skips installing a database, since that is set up as part of the docker process).
    ```bash
    /var/scripts/install-wp-tests.sh wordpress_test root '' phpunit_db latest true
    ```

    Sometimes the download will time out. If that happens, you can delete `/tmp/wp` from the container, and re-run the install script. The test files will be added to the `.docker/test_suite` folder, which is ignored by git.

3. Now you can run `phpunit`. From the project folder on your machine:
    ```bash
    docker-compose -f docker-compose.phpunit.yml exec phpunit_wp phpunit
    ```

    If you're still in the shell from the previous step, you can run `phpunit` directly.
    ```bash
    phpunit
    ```

    Either way, you'll see "Installing...", and then the tests will run.

4. The "useful commands" from the previous section will work here too— you just need to use `docker-compose -f docker-compose.phpunit.yml` to specify this configuration. 

    For example, to suspend the container, use:
    ```bash
    docker-compose -f docker-compose.phpunit.yml stop
    ```

5. You can run `test:watch` to automatically watch files for changes, and re-run the tests:

	```
	composer run test:watch
	```

	You can pass extra PHPUnit commands after the `--`. For example:

	```
	composer run test:watch -- --filter=<partial name of test>
	```

	Most of the time, though, you'll probably want to run `test:watch:fast`, which avoids re-installing WP before every run:

	```
	composer run test:watch:fast
	```

	It can shave several seconds off of each test run, but has two major downsides that you need to be aware of:

	1. Tests that depend on a fresh database will sometimes fail even though they should pass. If you suspect that you're seeing a false-positive or -negative, you can try manually running it again by pressing `<Enter>` in the interactive prompt. If that doesn't work, run the standard `composer run test` command in a separate terminal to re-install the database, and then press `<Enter>` at the interactive prompt in order to re-run the tests. For details see [#43432-core](https://core.trac.wordpress.org/ticket/43432), especially [comment:3](https://core.trac.wordpress.org/ticket/43432#comment:3).

	2. Sometimes it the database will be left in a broken state, and you'll get `One or more database tables are unavailable` errors. To fix those, run:

		```
		composer run test:db:reset
		```

## Working with the database provision file

The **.docker/bin** directory gets mounted as a volume within the PHP container, and it contains a script, **database.sh**, with several useful commands. To run these commands, first open a shell inside the Docker container:

```bash
docker-compose exec wordcamp.test bash
```

From there you can run the script using `bash /var/scripts/database.sh [subcommand]`. The most useful subcommands are:

* `backup-current`: Back up the current state of the database to a file in the **.docker/data** directory.
* `restore <file>`: Import a specified file to the database after deleting the current state.
* `reset`: Delete the current state of the database and then re-import the provision file.
* `clean-export`: Clean up the current state of the database and then export it to the provision file. See more on this below.
* `help`: See the full list of subcommands available.

### Updating the database provision file

**WARNING:** Never export data directly from the production database, because it contains sensitive information. Instead, manually recreate the useful data locally with fake email addresses, etc.

If the dev database needs to be updated to better reflect the state of production (e.g. a new version of the WP Core database, new network-activated plugins), or you've added data to your dev database that you think should be included for everyone (e.g. a new site with useful test cases), you can use the `clean-export` subcommand to update the file that is committed to version control. Before you do, please do these preflight checks:

* Make sure WP is running the latest branch, and the database schema has been updated.
* Review each line of the diff to make sure there isn't anything sensitive in the database. Scrub anything that is. There are some suggested strategies for reviewing database file diffs [here](https://github.com/WordPress/meta-environment/wiki/Reviewing-PRs-with-database-changes).

Then you can run `bash /var/scripts/database.sh clean-export`. It will automatically strip all post revisions, trashed posts, and transients from the database before dumping it into the **wordcamp_dev.sql** provision file.
