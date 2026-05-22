# WordCamp Blocks

## Getting Started

1. If you didn't run `npm install` when setting up this repo, run it now to install all dependencies.
2. `cd` into this directory.
2. Run `npm run build` to generate the built files.
3. Run `npm run start` while developing to continuously watch the files, this will automatically re-build the files when the source changes.


## Scripts

Check `package.json` for the scripts that are available around builds, testing, linting, etc.

General notes:

* When passing a path as an argument, it generally needs to start from the `mu-plugins/blocks` folder,
	* e.g. `npm run --workspace=wordcamp-blocks lint:js source/blocks/speakers`
	* Alternatively, `npm run lint:js source/blocks/speakers` from this directory.
* Arguments for the proxied command (e.g., `eslint`) need to be separated by a `--`.
	* e.g., `npm run lint:js -- --fix`, `npm run test -- -h`.


## Testing

We use Jest for testing, run `npm test`. With Jest, you can create snapshot tests for components.

**Writing tests**

1. Follow the example in `source/components/item/tests/index.test.js`
2. The first time you run `npm test` with new tests, it will generate the snapshots.
3. If you need to update existing snapshots, run `npm test -- --updateSnapshot`

You can also write non-snapshot tests using Jest to test regular function behavior, [see `expect` docs](https://jestjs.io/docs/en/expect) for examples.

**Running tests**

Run all tests with `npm test`, or run specific tests by passing in a path, `npm test [path-to-file-or-folder]`

If the component's output doesn't match the snapshot, the test fails. This is usually because the component changed, either intentionally or not. If it's not an intentional change, you just caught a bug 🙂

If the component changed intentionally:

- Regenerate the snapshot: `npm test -- --updateSnapshot`
- Review the changes, and commit the new snapshot with the component changes
