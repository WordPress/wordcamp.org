# WordCamp Blocks

## Getting Started

1. If you didn't run `yarn` when setting up this repo, run it now to install all dependencies.
2. `cd` into this directory.
2. Run `yarn build` to generate the built files.
3. Run `yarn start`  while developing to continuously watch the files, this will automatically re-build the files when the source changes.


## Scripts

Check `package.json` for the scripts that are available around builds, testing, linting, etc.

General notes:

* When passing a path as an argument, it generally needs to start from the `mu-plugins/blocks` folder,
	* e.g. `yarn workspace wordcamp-blocks lint:js source/blocks/speakers`
	* Alternatively, `npm run lint:js source/blocks/speakers`
* Arguments for `npm run` can be passed as you'd expect, but arguments for the proxied command (e.g., `eslint`) need to be separated by a `--`. That isn't needed for `yarn`, though.
	* e.g., `npm run lint:js -- --fix`, `npm run test -- -h`.
	* e.g., `yarn workspace wordcamp-blocks lint:js --fix`


## Testing

We use Jest for testing, run `yarn test`. With Jest, you can create snapshot tests for components.

**Writing tests**

1. Follow the example in `source/components/item/tests/index.test.js`
2. The first time you run `yarn test` with new tests, it will generate the snapshots.
3. If you need to update existing snapshots, run `yarn test --updateSnapshot`

You can also write non-snapshot tests using Jest to test regular function behavior, [see `expect` docs](https://jestjs.io/docs/en/expect) for examples.

**Running tests**

Run all tests with `yarn test`, or run specific tests by passing in a path, `yarn test [path-to-file-or-folder]`

If the component's output doesn't match the snapshot, the test fails. This is usually because the component changed, either intentionally or not. If it's not an intentional change, you just caught a bug 🙂

If the component changed intentionally:

- Regenerate the snapshot: `yarn test --updateSnapshot`
- Review the changes, and commit the new snapshot with the component changes
