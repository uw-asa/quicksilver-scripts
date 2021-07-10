<?php

// Dump debug info
include './debug.php';

// Important constants :)
$pantheon_yellow = '#EFD01B';

// Default values for parameters - this will assume the channel you define the webhook for.
// The full Slack Message API allows you to specify other channels and enhance the messagge further
// if you like: https://api.slack.com/docs/messages/builder
$defaults = array(
  'slack_username' => 'Pantheon-Quicksilver',
  'slack_channel' => NULL,
  'always_show_text' => false,
);

// Load our hidden credentials.
// See the README.md for instructions on storing secrets.
$secrets = _get_secrets(array('slack_url'), $defaults);

// Build an array of fields to be rendered as a section block:
// https://api.slack.com/reference/block-kit/blocks#section
$fields = array(
  array(
    'type'  => 'mrkdwn',
    'text'  => "*Site*\n" . $_ENV['PANTHEON_SITE_NAME'],
  ),
  array( // Render Environment name with link to site, <http://{ENV}-{SITENAME}.pantheon.io|{ENV}>
    'type'  => 'mrkdwn',
    'text'  => "*Environment*\n<http://" . $_ENV['PANTHEON_ENVIRONMENT'] . '-' . $_ENV['PANTHEON_SITE_NAME'] . '.pantheonsite.io|' . $_ENV['PANTHEON_ENVIRONMENT'] . '>',
  ),
  array( // Render Name with link to Email from Commit message
    'type'  => 'mrkdwn',
    'text'  => "*By*\n" . $_POST['user_email'],
  ),
  array( // Render workflow phase that the message was sent
    'type'  => 'mrkdwn',
    'text'  => "*Workflow*\n" . ucfirst($_POST['stage']) . ' ' . str_replace('_', ' ',  $_POST['wf_type']),
  ),
  array(
    'type'  => 'mrkdwn',
    'text'  => "*View Dashboard*\n<https://dashboard.pantheon.io/sites/". PANTHEON_SITE .'#'. PANTHEON_ENVIRONMENT .'/deploys|View Dashboard>',
  ),
);

$text = $_POST['qs_description'];
$blocks = array(
  array(
    'type' => 'header',
    'text' => array(
      'type' => 'plain_text',
      'text' => ($_POST['wf_type'] == 'clear_cache') ? 'Caches cleared :construction:' : 'Deploying :rocket:',
    )
  )
);

// Customize the message based on the workflow type.  Note that slack_notification.php
// must appear in your pantheon.yml for each workflow type you wish to send notifications on.
switch($_POST['wf_type']) {
  case 'deploy':
    // Find out what tag we are on and get the annotation.
    $deploy_tag = `git describe --tags`;
    $deploy_message = $_POST['deploy_message'];

    // Prepare the slack payload as per:
    // https://api.slack.com/incoming-webhooks
    $text = 'Deploy to the '. $_ENV['PANTHEON_ENVIRONMENT'];
    $text .= ' environment of '. $_ENV['PANTHEON_SITE_NAME'] .' by '. $_POST['user_email'] .' complete!';
    $text .= ' <https://dashboard.pantheon.io/sites/'. PANTHEON_SITE .'#'. PANTHEON_ENVIRONMENT .'/deploys|View Dashboard>';
    // Build an array of fields to be rendered with Slack Attachments as a table
    // attachment-style formatting:
    // https://api.slack.com/docs/attachments
    $fields[] = array(
      'type'  => 'mrkdwn',
      'text'  => "*Details*\n" . $text,
    );
    $fields[] = array(
      'type'  => 'mrkdwn',
      'text'  => "*Deploy Note*\n" . $deploy_message,
    );  
    break;

  case 'sync_code':
    // Get the committer, hash, and message for the most recent commit.
    $committer = `git log -1 --pretty=%cn`;
    $email = `git log -1 --pretty=%ce`;
    $message = `git log -1 --pretty=%B`;
    $hash = `git log -1 --pretty=%h`;

    // Prepare the slack payload as per:
    // https://api.slack.com/incoming-webhooks
    $text = 'Code sync to the ' . $_ENV['PANTHEON_ENVIRONMENT'] . ' environment of ' . $_ENV['PANTHEON_SITE_NAME'] . ' by ' . $_POST['user_email'] . "!\n";
    $text .= 'Most recent commit: ' . rtrim($hash) . ' by ' . rtrim($committer) . ': ' . $message;
    // Build an array of fields to be rendered with Slack Attachments as a table
    // attachment-style formatting:
    // https://api.slack.com/docs/attachments
    $fields += array(
      array(
        'type'  => 'mrkdwn',
        'text'  => "*Commit*\n" . rtrim($hash),
      ),
      array(
        'type'  => 'mrkdwn',
        'text'  => "*Commit Message*\n" . $message,
      )
    );
    break;

  case 'clear_cache':
    $fields[] = array(
      'type'  => 'mrkdwn',
      'text'  => "*Cleared caches*\nCleared caches on the " . $_ENV['PANTHEON_ENVIRONMENT'] . ' environment of ' . $_ENV['PANTHEON_SITE_NAME'] . "!",
    );
    break;

  default:
    break;
}

$fieldsection = array(
  'type' => 'section',
  'fields' => $fields
);

if ($secrets['always_show_text']) {
  $fieldsection['text'] = array(
    'type' => 'plain_text',
    'text' => $text,
  );
}

$blocks[] = $fieldsection;

_slack_notification($secrets['slack_url'], $secrets['slack_channel'], $secrets['slack_username'], $text, $blocks);


/**
 * Get secrets from secrets file.
 *
 * @param array $requiredKeys  List of keys in secrets file that must exist.
 */
function _get_secrets($requiredKeys, $defaults)
{
  $secretsFile = $_SERVER['HOME'] . '/files/private/secrets.json';
  if (!file_exists($secretsFile)) {
    die('No secrets file found. Aborting!');
  }
  $secretsContents = file_get_contents($secretsFile);
  $secrets = json_decode($secretsContents, 1);
  if ($secrets == false) {
    die('Could not parse json in secrets file. Aborting!');
  }
  $secrets += $defaults;
  $missing = array_diff($requiredKeys, array_keys($secrets));
  if (!empty($missing)) {
    die('Missing required keys in json secrets file: ' . implode(',', $missing) . '. Aborting!');
  }
  return $secrets;
}

/**
 * Send a notification to slack
 */
function _slack_notification($slack_url, $channel, $username, $text, $blocks)
{
  $post = array(
    'username' => $username,
    'channel' => $channel,
    'icon_emoji' => ':lightning_cloud:',
    'text' => $text,
    'blocks' => $blocks,
  );
  $payload = json_encode($post);
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $slack_url);
  curl_setopt($ch, CURLOPT_POST, 1);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_TIMEOUT, 5);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
  curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
  // Watch for messages with `terminus workflows watch --site=SITENAME`
  print("\n==== Posting to Slack ====\n");
  $result = curl_exec($ch);
  print("RESULT: $result\n");
  $payload_pretty = json_encode($post,JSON_PRETTY_PRINT); // Uncomment to debug JSON
  print("JSON: $payload_pretty\n"); // Uncomment to Debug JSON
  print("\n===== Post Complete! =====\n");
  curl_close($ch);
}