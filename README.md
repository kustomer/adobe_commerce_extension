# Kustomer <> Magento/Adobe Commerce Integration

This extension allows you to integrate Magento/Adobe Commerce with Kustomer. It works by simply observing events that happen in Adobe Commerce and sending them to Kustomer in as a webhook. This extension does not do any processing of data, and instead, sends the ID's of the affected entities over to the Kustomer app. It's up to the Kustomer server app to handle the retrieving, processing, and transformation of that payload into something that Kustomer can store and display in the UI. While the extension itself is very lightweight, there are a few features to be aware of.

## Configuration

Configuration of this Adobe Commerce extension is quite simple. You must also make sure that you have installed the Adobe Commerce app for Kustomer on the Kustomer platform. This app will provide you with the webhook URL and the secret key that you will need to configure this extension. To connect the app and the extension, follow the instructions on the app to create an Integration between Adobe Commerce and the Kustomer app. Once saved, that should be all you need to do to get the extension working.

## Event Logging

This extension logs all events that it sends to Kustomer. This is useful for debugging purposes, and also for auditing purposes. You can view the logs by going to Kustomer -> Event Logs on the Adobe Commerce admin panel.

## Webhook Retries

If an event fails to send to Kustomer, you're able to manually retry the event by clicking the "Retry" button on the Kustomer -> Event Logs admin panel page. It's worth noting that you can retry failed and successful events. Retrying a successful event will simply update that data in Kustomer with the latest data from Adobe Commerce, should it change after the initial event was sent. This does not create a new kobject in Adobe Commerce, but rather updates the existing event.

## Exporting

You can also export the logs to a JSON file from the Kustomer -> Event Logs admin panel page by clicking the "Export to JSON" button at the top of the page. This will download a JSON dump of all webhook events that have been sent to Kustomer, successful or not.
