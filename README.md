# Kaushik Sannidhi’s FluentSupport Email Parser
**Version 1.0.1**  
**Author:** Kaushik Sannidhi  
**License:** GPL v2 or later  

---

## Description
This plugin automatically converts incoming emails into FluentSupport tickets by periodically checking an IMAP inbox (or via a direct-trigger REST endpoint) and forwarding parsed messages to your FluentSupport webhook. It logs activity, tracks connection statistics, and supports both WP-Cron and a server cron for reliable processing.

---

## Features
- **IMAP Email Parsing**  
  - Connects securely (IMAP/SSL) to a specified mailbox.  
  - Fetches up to *N* unread emails per check (configurable).  
  - Marks processed messages as “Seen.”  
  - Extracts sender name, email, subject, and body (plain or HTML).  

- **FluentSupport Ticket Creation**  
  - Builds a standard ticket payload (`title`, `content`, `priority`, `sender` fields).  
  - Sends each email as a RESTful POST to your configured FluentSupport webhook.  
  - Retries and logs any failed webhook requests.  

- **Flexible Scheduling**  
  - Defaults to WP-Cron with intervals of 1, 2, 5, or 15 minutes.  
  - Optionally disable WP-Cron and use your own server cron to hit a REST endpoint for “direct trigger” processing.  

- **Built-In Testing & Monitoring**  
  - **Test Email Connection**: Verifies IMAP login, reports total/unread message counts.  
  - **Test Webhook**: Sends a dummy ticket to your FluentSupport endpoint and displays the response.  
  - **Check Emails Now**: Manually kick off a one-time fetch and ticket creation.  
  - **Clear Logs**: Wipe stored activity logs.  
  - **Recent Activity Logs**: Displays the last 50 log entries (success/info/error) with timestamps.  
  - **Connection Statistics**: Tracks total/successful/failed connection attempts (resets weekly).  

- **Detailed Error Logging**  
  - Captures connection errors, parsing exceptions, and webhook failures.  
  - Stores up to 100 entries; retains an 8-second rolling history (timestamps in WordPress time zone).  

- **REST API Endpoint (Direct Trigger)**  
  - `GET /wp-json/fluent-support/v1/check-emails?key=<secret>`  
  - Secured by a generated trigger key stored in settings.  
  - Returns success or error message in JSON format.

---

## Installation
1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin through the “Plugins” menu in WordPress.
3. Go to **Settings → Email Parser** to configure.

---

## Configuration
1. **Email Configuration**  
   - **Email Address**: The IMAP login (e.g., `support@example.com`).  
   - **Email Password**: App‐specific password or IMAP password.  
   - **IMAP Server**: e.g., `imap.gmail.com` or your host’s IMAP endpoint.  
   - **IMAP Port**: Defaults to `993` for SSL.  

2. **FluentSupport Webhook URL**  
   - Paste your FluentSupport ticket creation webhook endpoint.  

3. **Check Interval**  
   - Choose between “Every 1 Minute (High Frequency)”, “Every 2 Minutes (Recommended)”, “Every 5 Minutes (Balanced)”, or “Every 15 Minutes (Conservative)”.  

4. **Connection Timeout**  
   - Seconds before dropping IMAP or webhook attempts (default = 30).  

5. **Max Emails Per Check**  
   - Maximum number of unread emails processed each run (default = 10).  

6. **Enable Parser**  
   - Turn the parser on/off globally.  

7. **Debug Mode**  
   - When enabled, logs extra details for debugging.  

8. **Use Direct Trigger Endpoint**  
   - Check this to disable WP-Cron scheduling.  
   - A secret `trigger_key` will be generated.  
   - Use `curl -s https://your-site.com/wp-json/fluent-support/v1/check-emails?key=<trigger_key>` in your server cron.  

---

## Usage
- After configuration, the plugin will check for new emails on the chosen interval and send each unread message to FluentSupport as a ticket.  
- Use the **Testing & Monitoring** section in the plugin’s settings page to:
  - Verify your IMAP credentials.
  - Verify your webhook endpoint.
  - Manually trigger an email check.
  - Clear or view recent logs.

---

## Activity Logs & Connection Stats
- **Recent Activity Logs**  
  Shows timestamped entries (up to 50) of:
  - Successful ticket creations
  - Errors (connection, parsing, webhook)
  - Informational messages (e.g., “No unread emails found”)

- **Connection Statistics**  
  - **Total Connections**: Attempts made since last weekly reset.  
  - **Successful Connections**: IMAP logins that succeeded.  
  - **Failed Connections**: IMAP logins or API calls that failed.  
  - **Success Rate**: Percentage of successful attempts.  
  - **Last Check**: Date/time of the most recent check.

---

## Frequently Asked Questions

**Q: My WP-Cron seems unreliable. How can I ensure checks run regularly?**  
- Enable “Use Direct Trigger Endpoint” and schedule a real server cron job (e.g., every 2 minutes):
- */2 * * * * curl -s https://your-site.com/wp-json/fluent-support/v1/check-emails?key=<trigger_key> > /dev/null 2>&1
  
**Q: Why aren’t my emails being processed?**  
1. Ensure “Enable Parser” is checked.  
2. Check for missing required fields (Email, Password, IMAP Server, Webhook URL).  
3. Click “Test Email Connection” to verify IMAP login.  
4. Review Activity Logs for errors.

**Q: Can I process more than 10 emails at a time?**  
- Yes. Adjust “Max Emails Per Check” under Settings → Email Parser.

**Q: How do I reset the weekly connection statistics?**  
- Stats automatically reset 7 days after the last reset. To force‐reset, manually update the `last_reset` timestamp in your database or deactivate/reactivate the plugin.

---

## Changelog
**1.0.1**  
- Added “Debug Mode” toggle for verbose logging.  
- Improved error handling during IMAP connection.  
- Fixed REST endpoint permission callback for direct trigger.

**1.0.0**  
- Initial release: IMAP checking, FluentSupport webhook integration, WP-Cron scheduling, admin settings page, logging, and direct trigger endpoint.

---

## License
This plugin is licensed under the **GPL v2 or later**.  
Feel free to modify and redistribute under the same license.  
