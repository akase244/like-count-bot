# like-count-bot

いいね太郎botをカウントするためのbotです。

以下の箇所を環境に応じて書き換えてください。

- `app/Console/Commands/LikeCount.php`

```
// Slack Webhook URL
private $slack_webhook_url = 'https://hooks.slack.com/services/REPLACE_YOUR_ACCESS_TOKEN'; // REPLACE POINT

// Slack API Token
private $slack_api_token = 'REPLACE_YOUR_API_TOKEN'; // REPLACE POINT
・
・
・
private function setLikeUsers ()
{
    // Slack API(channels.list)でチャンネルの一覧を取得
    $channels = $this->getChannels();
    foreach ($channels as $channel) {
        ・
        ・
        ・
        foreach ($channel_history_messages as $channel_history_message) {
            ・
            ・
            ・
            if (isset($like_infos['user'])) {
                ・
                ・
                ・
                $comment = '<https://REPLACE_YOUR_HOST_NAME.slack.com/archives/'.$channel->name.'/p'.str_replace('.', '', $channel_history_message->ts).'|'.$comment.'>'; // REPLACE POINT
            }
        }
    }
}
```

- `app/Console/Kernel.php`

```
protected function schedule(Schedule $schedule)
{
    $schedule->command('like_count')
             ->cron('15 12 * * 1-5'); // CHANGE TO THE TIME YOU WANT TO POST.
}
```
