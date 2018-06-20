<?php

namespace Bot;

use Entity\BotProcessStatistics;
use Entity\Comment;
use Entity\FollowedUser;
use InstagramScraper\Exception\InstagramRequestException;
use InstagramScraper\Instagram;
use InstagramScraper\Model\Account;

use Repository\CommentsRepository;
use Repository\FollowsRepository;
use Unirest;
use Util\DatabaseWorker;
use Util\Logger;

abstract class Bot{
    const MAX_FAILS_COUNT = 15;
    const REQUEST_DELAY = 240;

    protected $instagram;
    private $commentsText = ['Like it!', 'Nice pic', 'Awesome ☺',
        'Nice image!!!', 'Cute ♥', "👍👍👍", "🔝🔝🔝", "🔥🔥🔥"];

    protected $likesSelected = false;
    protected $commentsSelected = false;
    protected $followingSelected = false;

    private $failsCount = 0;

    private $botProcessStatistics;

    /**
     * Bot constructor.
     * @param Instagram $instagram
     * @param array $settings
     */
    protected function __construct(Instagram $instagram, array $settings)
    {
        $this->instagram = $instagram;
        $this->botProcessStatistics = new BotProcessStatistics();

        if (isset($settings)) {
            if (array_key_exists('likes_selected', $settings))
                $this->likesSelected = $settings['likes_selected'];
            if (array_key_exists('comments_selected', $settings))
                $this->commentsSelected = $settings['comments_selected'];
            if (array_key_exists('following_selected', $settings))
                $this->followingSelected = $settings['following_selected'];
        }
    }

    /**
     * @throws InstagramRequestException
     */
    public function run(){

        try {
            if ($this->followingSelected || $this->likesSelected || $this->commentsSelected)
                $this->start();
        } catch (InstagramRequestException $e) {
            if ($this->failsCount++ < static::MAX_FAILS_COUNT)
                switch ($e->getCode()) {
                    case 403:
                    case 503:
                        Logger::log("Bot crush: ".$e->getMessage().PHP_EOL.
                            $e->getTraceAsString());
                        sleep(static::REQUEST_DELAY);
                        $this->run();
                        break;
                    default:
                        throw $e;
                }
            else
                throw new \Exception("Request failed");
        } catch (Unirest\Exception $e){
            Logger::log("Bot crush: ".$e->getMessage().PHP_EOL.
                $e->getTraceAsString());
            $this->run();
        } finally {
            $this->failsCount = 0;
            $this->botProcessStatistics = new BotProcessStatistics();
        }
    }

    /**
     * @return mixed
     * @throws InstagramRequestException
     */
    abstract protected function start();

    /**
     * @param $accounts
     * @throws Exception
     * @throws InstagramRequestException
     * @throws \InstagramScraper\Exception\InstagramException
     * @throws \InstagramScraper\Exception\InstagramNotFoundException
     */
    protected function processing($accounts)
    {
        foreach ($accounts as $account) {

            $accountObject = (gettype($account) == "object"
                ? $account
                : $this->instagram->getAccountById($account['id']));

            echo $accountObject->getUsername() . "\n";

            if ($accountObject->getUsername() != $this->instagram->getSessionUsername()) {

                if ($this->followingSelected && mt_rand(0, 1) == 1)
                    $this->follow($accountObject);

                if (!$accountObject->isPrivate()) {
                    if ($this->likesSelected && mt_rand(0, 1) == 1)
                        $this->likeAccountsMedia($accountObject);

                    if ($this->commentsSelected && mt_rand(0, 3) == 1)
                        $this->commentAccountsMedia($accountObject);
                }
            }
        }
    }

    /**
     * @param $accountObject
     * @throws \InstagramScraper\Exception\InstagramException
     * @throws \InstagramScraper\Exception\InstagramNotFoundException
     * @throws \InstagramScraper\Exception\InstagramRequestException
     */
    protected function likeAccountsMedia($accountObject)
    {
        $medias = $this->instagram->getMedias($accountObject->getUsername(), 15);
        $count = mt_rand(3, 5);

        if (count($medias) > 0) {

            echo "Like\n\n";

            if ($count > count($medias))
                foreach ($medias as $media) {
                    $this->botProcessStatistics->likesCount++;
                    $this->instagram->like($media->getId());
                }
            else
                while ($count > 0) {
                    $index = mt_rand(0, count($medias) - 1);
                    $media = $medias[$index];

                    if (!$media->isLikedByViewer()) {
                        $this->botProcessStatistics->likesCount++;
                        $this->instagram->like($media->getId());
                        array_splice($medias, $index, 1);
                        $count--;
                    }
                }
        }
    }

    /**
     * @param $accountObject
     * @throws \InstagramScraper\Exception\InstagramException
     * @throws \InstagramScraper\Exception\InstagramNotFoundException
     * @throws \InstagramScraper\Exception\InstagramRequestException
     */
    protected function commentAccountsMedia($accountObject)
    {
        $medias = $this->instagram->getMedias($accountObject->getUserName(), 5);

        $commentableMedias = [];
        foreach ($medias as $media)
            if (!$media->isCommentDisable() && !$this->commentedByViewer($media->getId()))
                array_push($commentableMedias, $media);

        if (count($commentableMedias) > 0) {
            $this->botProcessStatistics->commentsCount++;
            $comment = $this->instagram->comment(
                $commentableMedias[mt_rand(0, count($commentableMedias) - 1)]->getId(),
                $this->commentsText[mt_rand(0, count($this->commentsText) - 1)]
            );

            CommentsRepository::add(new Comment(
                    $comment->getId(), $comment->getOwner()->getId(),
                    $comment->getPicId(), $comment->getText(), $comment->getCreatedAt())
            );

            echo "Comment: \n ID: " . strval($comment->getId()) . ' Text: ' . strval($comment->getText())
                . ' OwnerId: ' . strval($comment->getOwner()->getId()) . ' PicID: ' . strval($comment->getPicId()) . "\n\n";
        }
    }

    /**
     * @return BotProcessStatistics
     */
    public function getBotProcessStatistics(){
        return $this->botProcessStatistics;
    }

    /**
     * @param Account $account
     * @throws \InstagramScraper\Exception\InstagramException
     * @throws \InstagramScraper\Exception\InstagramNotFoundException
     * @throws \InstagramScraper\Exception\InstagramRequestException
     */
    private function follow(Account $account){
        $this->botProcessStatistics->followsCount++;
        $this->instagram->follow($account->getId());

        FollowsRepository::add(new FollowedUser($account->getId(),
            $this->instagram->getAccount($this->instagram->getSessionUsername())->getId()));

        echo "Follow: \n ID: "
            . strval($account->getUsername()) . ' Id: ' . strval($account->getId()) . "\n\n";
    }

    private function commentedByViewer($mediaId)
    {
        return (DatabaseWorker::execute("SELECT COUNT(media_id) FROM comments"
        .$this->instagram->getAccount($this->instagram->getSessionUsername())->getId()
        ." WHERE media_id=$mediaId LIMIT 1")[0][0] == 1);
    }
}