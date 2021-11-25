<?php
/*
 * ChatBridge, PocketMine-MP Plugin.
 *
 * Licensed under the Open Software License version 3.0 (OSL-3.0)
 * Copyright (C) 2020-present JaxkDev
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
use JaxkDev\DiscordBot\Plugin\Events\MemberJoined;
use JaxkDev\DiscordBot\Plugin\Events\MemberLeft;
use JaxkDev\DiscordBot\Plugin\Events\MessageSent;
use JaxkDev\DiscordBot\Plugin\Storage;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\Player;
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
        //This plugin can no longer function if discord closes,
        //note once closed it will never start again until server restarts.
        $this->plugin->getLogger()->critical("DiscordBot has closed, disabling plugin.");
        $this->plugin->getServer()->getPluginManager()->disablePlugin($this->plugin);
    }

    //--- Minecraft Events -> Discord Server ---//

    /**
     * @priority MONITOR
     * @ignoreCancelled
     */
    public function onMinecraftMessage(PlayerChatEvent $event): void{
        if(!$this->ready){
            //Unlikely to happen, discord will most likely be ready before anyone even joins.
            $this->plugin->getLogger()->debug("Ignoring chat event, discord is not ready.");
            return;
        }

        /** @var array $config */
        $config = $this->config->getNested("messages.minecraft");
        if(!$config['enabled']) return;

        $player = $event->getPlayer();
        $message = $event->getMessage();
        $world = $player->getLevelNonNull()->getName();

        $from_worlds = is_array($config["from_worlds"]) ? $config["from_worlds"] : [$config["from_worlds"]];
        if(!in_array("*", $from_worlds)){
            //Only specific worlds.
            if(!in_array($world, $from_worlds)){
                $this->plugin->getLogger()->debug("Ignoring chat event, world '$world' is not listed.");
                return;
            }
        }

        $formatter = function(string $text) use ($player, $message, $world): string{
            $text = str_replace(["{USERNAME}", "{username}", "{PLAYER}", "{player}"], $player->getName(), $text);
            $text = str_replace(["{DISPLAYNAME}", "{displayname}", "{DISPLAY_NAME}", "{display_name}", "{NICKNAME}", "{nickname}"], $player->getDisplayName(), $text);
            $text = str_replace(["{MESSAGE}", "{message}"], $message, $text);
            $text = str_replace(["{XUID}", "{xuid}"], $player->getXuid(), $text);
            $text = str_replace(["{UUID}", "{uuid}"], $player->getUniqueId()?->toString()??"", $text);
            $text = str_replace(["{ADDRESS}", "{address}", "{IP}", "{ip}"], $player->getAddress(), $text);
            $text = str_replace(["{PORT}", "{port}"], strval($player->getPort()), $text);
            $text = str_replace(["{WORLD}", "{world}", "{LEVEL}", "{level}"], $world, $text);
            $text = str_replace(["{TIME}", "{time}", "{TIME-1}", "{time-1}"], date("H:i:s", time()), $text);
            $text = str_replace(["{TIME-2}", "{time-2}"], date("H:i", time()), $text);
            return str_replace(["{TIME-3}", "{time-3}"], "<t:".time().":f>", $text); //TODO Other formatted times supported by discord.
        };

        $embed = null;
        $embed_config = $config["format"]["embed"];
        if($embed_config["enabled"]){
            $fields = [];
            foreach($embed_config["fields"] as $field){
                $fields[] = new Field($formatter($field["name"]), $formatter($field["value"]), $field["inline"]);
            }
            $embed = new Embed(($embed_config["title"] === null ? null : $formatter($embed_config["title"])), Embed::TYPE_RICH,
                ($embed_config["description"] === null ? null : $formatter($embed_config["description"])),
                $embed_config["url"], $embed_config["time"] ? time() : null, $embed_config["colour"],
                new Footer($embed_config["footer"] === null ? null : $formatter($embed_config["footer"])), null,
                null, null, new Author($embed_config["author"] === null ? null : $formatter($embed_config["author"])), $fields);
        }

        foreach($config["to_discord_channels"] as $cid){
            $message = new Message($cid, null, $formatter($config["format"]["text"]??""), $embed);
            $this->plugin->getDiscord()->getApi()->sendMessage($message)->otherwise(function(ApiRejection $rejection){
                $this->plugin->getLogger()->warning("Failed to send discord message on minecraft message event, '{$rejection->getMessage()}'");
            });
        }
    }

    //--- Discord Events -> Minecraft Server ---//

    /**
     * @priority MONITOR
     */
    public function onDiscordMemberJoin(MemberJoined $event): void{
        /** @var array $config */
        $config = $this->config->getNested("join.discord");
        if(!$config['enabled']) return;

        $member = $event->getMember();
        $server_id = $member->getServerId();
        if(!in_array($server_id, $config["servers"])){
            $this->plugin->getLogger()->debug("Ignoring member join event, discord server
            '$server_id' is not in config list.");
            return;
        }

        $server = Storage::getServer($server_id);
        if($server === null){
            //shouldn't happen, but can.
            $this->plugin->getLogger()->warning("Failed to process discord member join event, server
            '$server_id' does not exist in local storage.");
            return;
        }
        $user = Storage::getUser($member->getUserId());
        if($user === null){
            //shouldn't happen, but can.
            $this->plugin->getLogger()->warning("Failed to process discord member join event, user
            '{$member->getUserId()}' does not exist in local storage.");
            return;
        }

        //Format message.
        $message = str_replace(['{NICKNAME}', '{nickname}'], $member->getNickname()??$user->getUsername(), $config['format']);
        $message = str_replace(['{USERNAME}', '{username}'], $user->getUsername(), $message);
        $message = str_replace(['{USER_DISCRIMINATOR}', '{user_discriminator}', '{DISCRIMINATOR}', '{discriminator}'], $user->getDiscriminator(), $message);
        $message = str_replace(['{SERVER}', '{server}'], $server->getName(), $message);
        $message = str_replace(['{TIME}', '{time}', '{TIME-1}', '{time-1}'], date('G:i:s', $member->getJoinTimestamp()), $message);
        $message = str_replace(['{TIME-2}', '{time-2}'], date('G:i', $member->getJoinTimestamp()), $message);
        if(!is_string($message)){
            throw new AssertionError("A string is always expected, got '".gettype($message)."'");
        }

        //Broadcast.
        $worlds = $config['to_minecraft_worlds'];
        $players = $this->getPlayersInWorlds($worlds);

        $this->plugin->getServer()->broadcastMessage($message, $players);
    }

    /**
     * Who doesn't like some copy/paste?
     * @priority MONITOR
     */
    public function onDiscordMemberLeave(MemberLeft $event): void{
        /** @var array $config */
        $config = $this->config->getNested("leave.discord");
        if(!$config['enabled']) return;

        $member = $event->getMember();
        $server_id = $member->getServerId();
        if(!in_array($server_id, $config["servers"])){
            $this->plugin->getLogger()->debug("Ignoring member leave event, discord server '$server_id' is not in config list.");
            return;
        }

        $server = Storage::getServer($server_id);
        if($server === null){
            //shouldn't happen, but can.
            $this->plugin->getLogger()->warning("Failed to process discord leave join event, server '$server_id' does not exist in local storage.");
            return;
        }
        $user = Storage::getUser($member->getUserId());
        if($user === null){
            //shouldn't happen, but can.
            $this->plugin->getLogger()->warning("Failed to process discord member leave event, user '{$member->getUserId()}' does not exist in local storage.");
            return;
        }

        //Format message.
        $message = str_replace(['{NICKNAME}', '{nickname}'], $member->getNickname()??$user->getUsername(), $config['format']);
        $message = str_replace(['{USERNAME}', '{username}'], $user->getUsername(), $message);
        $message = str_replace(['{USER_DISCRIMINATOR}', '{user_discriminator}', '{DISCRIMINATOR}', '{discriminator}'], $user->getDiscriminator(), $message);
        $message = str_replace(['{SERVER}', '{server}'], $server->getName(), $message);
        $message = str_replace(['{TIME}', '{time}', '{TIME-1}', '{time-1}'], date('G:i:s', time()), $message);
        $message = str_replace(['{TIME-2}', '{time-2}'], date('G:i', time()), $message);
        if(!is_string($message)){
            throw new AssertionError("A string is always expected, got '".gettype($message)."'");
        }

        //Broadcast.
        $this->plugin->getServer()->broadcastMessage($message, $this->getPlayersInWorlds($config['to_minecraft_worlds']));
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

        $channel = Storage::getChannel($msg->getChannelId());
        if($channel === null){
            //shouldn't happen, but can.
            $this->plugin->getLogger()->warning("Failed to process discord message event, channel '{$msg->getChannelId()}' does not exist in local storage.");
            return;
        }
        if(!in_array($channel->getId()??"Will never be null", $config['from_channels'])){
            $this->plugin->getLogger()->debug("Ignoring message from channel '{$channel->getId()}', ID is not in list.");
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
            $this->plugin->getLogger()->warning("Failed to process discord message event, server '{$msg->getServerId()}' does not exist in local storage.");
            return;
        }
        $member = Storage::getMember($msg->getAuthorId()??""); //Member is not required, but preferred.
        $user_id = (($member?->getUserId()) ?? (explode(".", $msg->getAuthorId()?? "na.na")[1]));
        $user = Storage::getUser($user_id);
        if($user === null){
            //shouldn't happen, but can.
            $this->plugin->getLogger()->warning("Failed to process discord message event, author user '$user_id' does not exist in local storage.");
            return;
        }
        $content = trim($msg->getContent());
        if(strlen($content) === 0){
            //Files or other type of messages.
            $this->plugin->getLogger()->debug("Ignoring message '{$msg->getId()}', No text content.");
            return;
        }

        //Format message.
        $message = str_replace(['{NICKNAME}', '{nickname}'], ($member?->getNickname())??$user->getUsername(), $config['format']);
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
        $this->plugin->getServer()->broadcastMessage($message, $this->getPlayersInWorlds($config['to_minecraft_worlds']));
    }

    /**
     * Fetch players based on config worlds entry.
     *
     * @param string|string[] $worlds
     * @return Player[]
     */
    private function getPlayersInWorlds(array|string $worlds): array{
        $players = [];
        if($worlds === "*" or (is_array($worlds) and sizeof($worlds) === 1 and $worlds[0] === "*")){
            $players = $this->plugin->getServer()->getOnlinePlayers();
        }else{
            foreach((is_array($worlds) ? $worlds : [$worlds]) as $world){
                $level = $this->plugin->getServer()->getLevelByName($world);
                if($level === null){
                    $this->plugin->getLogger()->warning("World '$world' specified in config.yml does not exist.");
                }else{
                    $players = array_merge($players, $level->getPlayers());
                }
            }
        }
        return $players;
    }
}