# WordCamp QBO

This connects to QuickBooks API to send sponsorship invoices, etc.

There's also relevant code in `plugins/wordcamp-qbo-client` and `mu-plugins/quickbooks`.

## Testing

w.org sandboxes use the production QBO company, since they also use the production database. You can use one to debug errors that are happening on production.

For _local_ testing with sample data:

1. Define `WORDCAMP_SANDBOX_QBO_CLIENT_ID` and `WORDCAMP_SANDBOX_QBO_CLIENT_SECRET` in your `wp-config.php`. To get the values, log in to https://developer.intuit.com/app/developer/dashboard, click on the `WordCamp Production` app, then click on `Keys & credentials` under **Development Settings**.
    ⚠️ Don't use the keys from `Production Settings`, those should never be stored on a local machine.
1. Open https://wordcamp.test/wp-admin/network/settings.php?page=quickbooks and connect to QBO. Use the `Sandbox WPCS` company.
1. Do something to trigger a request, like approving an invoice at https://wordcamp.test/wp-admin/network/admin.php?page=sponsor-invoices-dashboard
1. Log to https://app.sandbox.qbo.intuit.com/ to see invoices etc that you create, to verify that it looks correct on the QBO side.
1. Check the logs with `wp wc-misc format-log wp-content/debug.log`
