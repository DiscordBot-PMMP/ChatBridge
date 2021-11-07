<?php
/*
 * ChatBridge, PocketMine-MP Plugin.
 *
 * Licensed under the Open Software License version 3.0 (OSL-3.0)
 * Copyright (C) 2020-2021 JaxkDev
 *
 * Twitter :: @JaxkDev
 * Discord :: JaxkDev#2698
 * Email   :: JaxkDev@gmail.com
 */

namespace JaxkDev\ChatBridge;

use AssertionError;
use JaxkDev\DiscordBot\Models\Messages\Embed\Author;
use JaxkDev\DiscordBot\Models\Messages\Embed\Embed;
use JaxkDev\DiscordBot\Models\Messages\Embed\Field;
use JaxkDev\DiscordBot\Models\Messages\Embed\Footer;
use JaxkDev\DiscordBot\Models\Messages\Message;
use JaxkDev\DiscordBot\Models\Messages\Webhook;
use JaxkDev\DiscordBot\Plugin\ApiRejection;
use JaxkDev\DiscordBot\Plugin\Events\DiscordClosed;
use JaxkDev\DiscordBot\Plugin\Events\DiscordReady;
use JaxkDev\DiscordBot\Plugin\Events\MessageSent;
use JaxkDev\DiscordBot\Plugin\Storage;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\utils\Config;

class EventListener implements Listener{

    /** @var bool */
    private $ready = false;

    /** @var Main */
    private $plugin;

    /** @var Config */
    private $config;

    public function __construct(Main $plugin){
        $this->plugin = $plugin;
        $this->config = $this->plugin->getConfig();
    }

    public function onDiscordReady(DiscordReady $event): void{
        $this->ready = true;
    }

    public function onDiscordClosed(DiscordClosed $event): void{
        //This plugin can no longer function if discord closes, note once closed it will never start again until server restarts.
        $this->plugin->getLogger()->critical("DiscordBot has closed, disabling plugin.");
        $this->plugin->getServer()->getPluginManager()->disablePlugin($this->plugin);
    }

    /**
     * @priority MONITOR
     * @ignoreCancelled
     */
    public function onMinecraftMessage(PlayerChatEvent $event): void{
        if(!$this->ready){
            //TODO, Should we stack the messages up and send all 3/s when discords ready or....
            $this->plugin->getLogger()->debug("Ignoring chat event, discord is not ready.");
            return;
        }

        /** @var array $config */
        $config = $this->config->getNested("messages.minecraft");
        if(!$config['enabled']) return;

        $player = $event->getPlayer();

        $from_worlds = is_array($config["from_worlds"]) ? $config["from_worlds"] : [$config["from_worlds"]];
        if(!in_array("*", $from_worlds)){
            //Only specific worlds.
            $world = $player->getLevelNonNull()->getName();
            if(!in_array($world, $from_worlds)){
                $this->plugin->getLogger()->debug("Ignoring chat event, world '$world' is not listed.");
                return;
            }
        }

        $embed = null;
        $embed_config = $config["format"]["embed"];
        if($embed_config["enabled"]){
            $fields = [];
            foreach($embed_config["fields"] as $field){
                $fields[] = new Field($field["name"], $field["value"], $field["inline"]);
            }
            $embed = new Embed($embed_config["title"], Embed::TYPE_RICH, $embed_config["description"],
                $embed_config["url"], $embed_config["time"] ? time() : null, $embed_config["colour"],
                new Footer($embed_config["footer"]), null, null, null, new Author($embed_config["author"]), $fields);
        }

        foreach($config["to_discord_channels"] as $cid){
            $message = new Message($cid, null, $config["format"]["text"]??"", $embed);
            $this->plugin->getDiscord()->getApi()->sendMessage($message)->otherwise(function(ApiRejection $rejection){
                $this->plugin->getLogger()->warning("Failed to send discord message on minecraft message event, '{$rejection->getMessage()}'");
            });
        }
    }

    /**
     * @priority MONITOR
     */
    public function onDiscordMessage(MessageSent $event): void{
        /** @var array $config */
        $config = $this->config->getNested("messages.discord");
        if(!$config['enabled']) return;
        if(($msg = $event->getMessage()) instanceof Webhook){
            $this->plugin->getLogger()->debug("Ignoring message '{$msg->getId()}', Sent via webhook.");
            return;
        }

        $server_id = $msg->getServerId();
        if($server_id === null){
            //DM Channel.
            $this->plugin->getLogger()->debug("Ignoring message '{$msg->getId()}', Sent via DM to bot.");
            return;
        }
        $server = Storage::getServer($server_id);
        if($server === null){
            //shouldn't happen, but can.
            $this->plugin->getLogger()->warning("Failed to process discord message, server '{$msg->getServerId()}' does not exist in local storage.");
            return;
        }
        $channel = Storage::getChannel($msg->getChannelId());
        if($channel === null){
            //shouldn't happen, but can.
            $this->plugin->getLogger()->warning("Failed to process discord message, channel '{$msg->getChannelId()}' does not exist in local storage.");
            return;
        }
        $member = Storage::getMember($msg->getAuthorId()??"Will never be null");
        if($member === null){
            //shouldn't happen, but can.
            $this->plugin->getLogger()->warning("Failed to process discord message, author member '{$msg->getAuthorId()}' does not exist in local storage.");
            return;
        }
        $user = Storage::getUser($member->getUserId());
        if($user === null){
            //shouldn't happen, but can.
            $this->plugin->getLogger()->warning("Failed to process discord message, author user '{$member->getUserId()}' does not exist in local storage.");
            return;
        }
        $content = trim($msg->getContent());
        if(strlen($content) === 0){
            //Files or other type of messages.
            $this->plugin->getLogger()->debug("Ignoring message '{$msg->getId()}', No text content.");
            return;
        }
        if(!in_array($channel->getId()??"Will never be null", $config['from_channels'])){
            $this->plugin->getLogger()->debug("Ignoring message from channel '{$channel->getId()}', ID is not in list.");
            return;
        }

        //Format message.
        $message = str_replace(['{NICKNAME}', '{nickname}'], $member->getNickname()??$user->getUsername(), $config['format']);
        $message = str_replace(['{USERNAME}', '{username}'], $user->getUsername(), $message);
        $message = str_replace(['{USER_DISCRIMINATOR}', '{user_discriminator}', '{DISCRIMINATOR}', '{discriminator}'], $user->getDiscriminator(), $message);
        $message = str_replace(['{MESSAGE}', '{message'], $content, $message);
        $message = str_replace(['{SERVER}', '{server}'], $server->getName(), $message);
        $message = str_replace(['{CHANNEL}', '{channel}'], $channel->getName(), $message);
        $message = str_replace(['{TIME}', '{time}', '{TIME-1}', '{time-1}'], date('G:i:s', (int)($msg->getTimestamp()??time())), $message);
        $message = str_replace(['{TIME-2}', '{time-2}'], date('G:i', (int)($msg->getTimestamp()??time())), $message);
        if(!is_string($message)){
            throw new AssertionError("A string is always expected, got '".gettype($message)."'");
        }

        //Broadcast.
        $worlds = $config['to_minecraft_worlds'];
        $players = [];

        if($worlds === "*" or (is_array($worlds) and sizeof($worlds) === 1 and $worlds[0] === "*")){
            $players = $this->plugin->getServer()->getOnlinePlayers();
        }else{
            foreach((is_array($worlds) ? $worlds : [$worlds]) as $world){
                $level = $this->plugin->getServer()->getLevelByName($world);
                if($level === null){
                    $this->plugin->getLogger()->warning("World '$world' listed in discord message config does not exist.");
                }else{
                    $players = array_merge($players, $level->getPlayers());
                }
            }
        }

        $this->plugin->getServer()->broadcastMessage($message, $players);
    }
}