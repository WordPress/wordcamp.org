# wporg-events-2023

### Local Env Setup

1. `yarn`
1. `yarn build`
1. `composer install`
1. Set your `WORDCAMP_DEV_GOOGLE_MAPS_API_KEY` in `.wp-env.json` if you want to test Google Map.
1. `yarn wp-env start`
1. `yarn setup:wp`

### Environment management

* Stop the environment.

    ```bash
    yarn wp-env stop
    ```

* Restart the environment.

    ```bash
    yarn wp-env start
    ```

* Open a shell inside the docker container.

    ```bash
    yarn wp-env run wordpress bash
    ```

* Run wp-cli commands. Keep the wp-cli command in quotes so that the flags are passed correctly.

    ```bash
    yarn wp-env run cli "post list --post_status=publish"
    ```

* Watch for PCSS/JS changes and rebuild them as needed.

    ```bash
    yarn run watch
    ```
