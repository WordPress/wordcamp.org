# WordCamp Blocks

## Getting Started

1. `cd` into this directory and run `npm install`.
2. Run `npm run build` to initialize the build files.
3. Run `npm start`  while developing to continuously watch the files.

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
