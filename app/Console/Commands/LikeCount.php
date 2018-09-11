<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class LikeCount extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'like_count';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Like Count';

    // 全ユーザーの一覧
    private $all_members = [];

    // 社員の一覧
    private $employee_members = [];

    // Slack Webhook URL
    private $slack_webhook_url = 'https://hooks.slack.com/services/REPLACE_YOUR_ACCESS_TOKEN'; // REPLACE POINT

    // Slack API Token
    private $slack_api_token = 'REPLACE_YOUR_API_TOKEN'; // REPLACE POINT

    // 投稿文の抽出範囲
    private $range_start_ts;
    private $range_end_ts;

    // likeされたユーザーのリスト
    private $like_users = [];

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        // Guzzleクライアントのオブジェクト
        $this->guzzleClient = new \GuzzleHttp\Client();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        // 月曜から金曜日のみ実行
        if ((date('w') == 0 || date('w') == 6)) {
            return;
        }

        // 投稿日時の取得範囲を設定
        $this->setMessagePostRange();

        // 全ユーザー及び社員の一覧を作成
        $this->setMembers();

        // Slack APIからlikeを含む投稿文を抽出し$this->like_usersにセット
        $this->setLikeUsers();

        $like_message = $this->getLikeMessage();

        $req = $this->guzzleClient->post($this->slack_webhook_url,[
            'body' => json_encode([
                'channel' => '#counter-bot',
                'username' => 'いいね太郎カウンター',
                'text' => $like_message,
                'icon_emoji' => ':thumbsup:',
            ]),
        ]);
    }

    // 投稿日時の取得範囲を設定
    private function setMessagePostRange ()
    {
        // 前日の00:00:00
        $this->range_start_ts = strtotime('today -1 day');
        // 前日の23:59:59
        $this->range_end_ts = strtotime('today -1 seconds');
        if (date('w') == 1) {
            // 実行日が月曜日の場合は3日前(金曜日)の日付を取得
            // 金曜日の00:00:00
            $this->range_start_ts = strtotime('last Friday');
            // 金曜日の23:59:59
            $this->range_end_ts = strtotime('last Saturday -1 seconds');
        }
    }

    private function setMembers ()
    {
        // Slack API(users.list)でユーザーの一覧を取得
        $user_list_url = 'https://slack.com/api/users.list?token='.$this->slack_api_token.'&pretty=1';
        $user_list_res = $this->guzzleClient->get($user_list_url);
        $user_list_body = json_decode($user_list_res->getBody());

        foreach ($user_list_body->members as $member) {
            // 削除済みユーザーは除外
            if ($member->deleted === false) {
                // 対象を社員のみに絞る
                if (isset($member->is_restricted) && $member->is_restricted === false
                    && isset($member->is_ultra_restricted) &&  $member->is_ultra_restricted === false
                    && isset($member->is_bot) && $member->is_bot === false) {
                    $this->employee_members[$member->id]['name'] = $member->name;
                    $this->employee_members[$member->id]['real_name'] = isset($member->real_name) && $member->real_name ? $member->real_name: $member->name;
                }
                $this->all_members[$member->id]['name'] = $member->name;
                $this->all_members[$member->id]['real_name'] = isset($member->real_name) && $member->real_name ? $member->real_name: $member->name;
            }
        }
    }

    // Slack API(channels.list)でチャンネルの一覧を取得
    private function getChannels ()
    {
        $channel_list_url = 'https://slack.com/api/channels.list?token='.$this->slack_api_token.'&exclude_archived=true&pretty=1';
        $channel_list_res = $this->guzzleClient->get($channel_list_url);
        $channel_list_body = json_decode($channel_list_res->getBody());
        return $channel_list_body->channels;
    }

    // チャンネルの投稿履歴を取得
    private function getChannelHistoryMessages ($channel_id)
    {
        // Slack API(channels.history)でチャンネルの投稿履歴を取得
        $channel_history_url = 'https://slack.com/api/channels.history?token='.$this->slack_api_token.'&channel='.$channel_id.'&pretty=1';
        $channel_history_res = $this->guzzleClient->get($channel_history_url);
        $channel_history_body = json_decode($channel_history_res->getBody());
        return $channel_history_body->messages;
    }
    // 投稿文の妥当性チェック
    private function isValidMessage ($message)
    {
        // 前日の00:00:00〜前日の23:59:59の範囲外の場合はスキップ
        if ($message->ts < $this->range_start_ts || $message->ts > $this->range_end_ts) {
            return false;
        }

        // 投稿文が未定義の場合はスキップ
        if (!isset($message->text)) {
            return false;
        }

        // 投稿文の中に「like」を含まない場合はスキップ
        if (!$this->hasLikeMessage($message->text)) {
            return false;
        }

        // 社員以外の投稿文はスキップ
        if (!(isset($message->user) && isset($this->employee_members[$message->user]['name']))) {
            return false;
        }

        return true;
    }

    // 投稿文の中に「like」を含まない場合はスキップ
    private function hasLikeMessage ($message_text)
    {
        $hasLikeMessage = false;
        preg_match('/like\s+(\<@U\w+?\>)\s+.*/', $message_text, $matches);

        if (isset($matches[0]) && isset($matches[1])) {
            $replace_pairs = [
                '<' => '',
                '@' => '',
                '>' => '',
            ];
            $hasLikeMessage = true;
        }
        return $hasLikeMessage;
    }

    // 投稿文の中から「like」されたユーザーを取得
    private function getLikeInfos ($message_text)
    {
        $like_infos = [];
        preg_match('/like\s+(\<@U\w+?\>)\s+(.*)/', $message_text, $matches);

        if (isset($matches[0])) {
            if (isset($matches[1])) {
                $replace_pairs = [
                    '<' => '',
                    '@' => '',
                    '>' => '',
                ];
                $like_infos['user'] = strtr($matches[1], $replace_pairs);
            }

            if (isset($matches[2])) {
                $like_infos['comment'] = $matches[2];
            }
        }
        return $like_infos;
    }

    private function setLikeUsers ()
    {
        // Slack API(channels.list)でチャンネルの一覧を取得
        $channels = $this->getChannels();
        foreach ($channels as $channel) {
            // クライアントがメンバーとして含まれる可能性があるチャンネル(チャンネル名に「-pub」を含む)はスキップ
            if (strpos($channel->name, '-pub') !== false) {
                continue;
            }

            $channel_id = $channel->id;
            // チャンネルの投稿履歴を取得
            $channel_history_messages = $this->getChannelHistoryMessages($channel_id);
            foreach ($channel_history_messages as $channel_history_message) {
                // 投稿文の妥当性チェック
                if (!$this->isValidMessage($channel_history_message)) {
                    continue;
                }

                $like_infos = $this->getLikeInfos($channel_history_message->text);
                if (isset($like_infos['user'])) {
                    if (!isset($this->like_users[$like_infos['user']])) {
                        $this->like_users[$like_infos['user']] = [];
                    }
                    if (!isset($this->like_users[$like_infos['user']][$channel_history_message->user])) {
                        $this->like_users[$like_infos['user']][$channel_history_message->user] = [];
                    }
                    $comment = isset($like_infos['comment']) ? $like_infos['comment'] : 'LINK';
                    $comment = '<https://REPLACE_YOUR_HOST_NAME.slack.com/archives/'.$channel->name.'/p'.str_replace('.', '', $channel_history_message->ts).'|'.$comment.'>'; // REPLACE POINT
                    $this->like_users[$like_infos['user']][$channel_history_message->user][] = $comment;
                }
            }
        }
    }

    private function getLikeMessage ()
    {
        // likeを含むメッセージのカウント
        $all_like_count = 0;

        $like_text = '';
        foreach ($this->like_users as $to_like_user => $from_like_users) {
            if (isset($this->all_members[$to_like_user])) {
                $like_text .= '>'.$this->all_members[$to_like_user]['real_name'].PHP_EOL;
                foreach($from_like_users as $from_like_user => $comments) {
                    foreach($comments as $comment) {
                        $like_text .= '>:thumbsup: '.$this->all_members[$from_like_user]['real_name'].' ＜'.$comment.PHP_EOL;
                        $all_like_count++;
                    }
                }
                $like_text .= '>'.PHP_EOL;
            }
        }

        $header_text  = date('Y/m/d H:i:s', $this->range_start_ts);
        $header_text .= '〜';
        $header_text .= date('Y/m/d H:i:s', $this->range_end_ts);
        $header_text .= 'の「いいね太郎」は'.$all_like_count.'件でした。'.PHP_EOL;
        $message_text = $header_text.$like_text;
        return $message_text;
    }
}
