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
use JaxkDev\DiscordBot\Models\Channels\Channel;
use JaxkDev\DiscordBot\Models\Guild\Guild;
use JaxkDev\DiscordBot\Models\Member;
use JaxkDev\DiscordBot\Models\Messages\MessageType;
use JaxkDev\DiscordBot\Models\Presence\Activity\Activity;
use JaxkDev\DiscordBot\Models\Messages\Embed\Author;
use JaxkDev\DiscordBot\Models\Messages\Embed\Embed;
use JaxkDev\DiscordBot\Models\Messages\Embed\Field;
use JaxkDev\DiscordBot\Models\Messages\Embed\Footer;
use JaxkDev\DiscordBot\Models\Presence\Activity\ActivityType;
use JaxkDev\DiscordBot\Models\Presence\Status;
use JaxkDev\DiscordBot\Models\User;
use JaxkDev\DiscordBot\Plugin\Api;
use JaxkDev\DiscordBot\Plugin\ApiRejection;
use JaxkDev\DiscordBot\Plugin\ApiResolution;
use JaxkDev\DiscordBot\Plugin\Events\DiscordClosed;
use JaxkDev\DiscordBot\Plugin\Events\DiscordReady;
use JaxkDev\DiscordBot\Plugin\Events\MemberJoined;
use JaxkDev\DiscordBot\Plugin\Events\MemberLeft;
use JaxkDev\DiscordBot\Plugin\Events\MessageSent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerTransferEvent;
use pocketmine\event\plugin\PluginDisableEvent;
use pocketmine\event\server\CommandEvent;
use pocketmine\lang\Translatable;
use pocketmine\player\Player;

class EventListener implements Listener{

    private bool $ready = false;

    private Main $plugin;

    private array $config;

    private Api $api;

    public function __construct(Main $plugin){
        $this->plugin = $plugin;
        $this->config = $this->plugin->getConfig()->getAll();
        $this->api = $this->plugin->getDiscord()->getApi();
    }

    public function onDiscordReady(DiscordReady $event): void{
        $this->ready = true;

        //Update presence.
        $type = match(strtolower($this->config["presence"]["type"]??"Playing")){
            'listening', 'listen' => ActivityType::LISTENING,
            'watching', 'watch' => ActivityType::WATCHING,
            default => ActivityType::GAME,
        };
        $status = match(strtolower($this->config["presence"]["status"]??"Online")){
            'idle' => Status::IDLE,
            'dnd' => Status::DND,
            'offline' => Status::OFFLINE,
            default => Status::ONLINE
        };
        $activity = Activity::create($this->config["presence"]["message"]??"on a PMMP Server!", $type);
        $event->setActivity($activity);
        $event->setStatus($status);
    }

    public function onDiscordClosed(DiscordClosed $event): void{
        //This plugin can no longer function if discord closes,
        //note once closed it will never start again until server restarts.
        $this->plugin->getLogger()->critical("DiscordBot has closed, disabling plugin.");
        $this->plugin->getServer()->getPluginManager()->disablePlugin($this->plugin);
    }

    public function onDiscordDisabled(PluginDisableEvent $event): void{
        //Sometimes discordbot can be disabled without it emitting discord closed event. (startup errors)
        if($event->getPlugin()->getName() === "DiscordBot"){
            $this->plugin->getLogger()->critical("DiscordBot has been disabled, disabling plugin.");
            $this->plugin->getServer()->getPluginManager()->disablePlugin($this->plugin);
        }
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
        $config = $this->config["messages"]["minecraft"];
        if(!$config['enabled']){
            return;
        }

        $player = $event->getPlayer();
        $message = $event->getMessage();
        $world = $player->getWorld()->getDisplayName();

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
            $text = str_replace(["{UUID}", "{uuid}"], $player->getUniqueId()->toString(), $text);
            $text = str_replace(["{ADDRESS}", "{address}", "{IP}", "{ip}"], $player->getNetworkSession()->getIp(), $text);
            $text = str_replace(["{PORT}", "{port}"], strval($player->getNetworkSession()->getPort()), $text);
            $text = str_replace(["{WORLD}", "{world}", "{LEVEL}", "{level}"], $world, $text);
            $text = str_replace(["{TIME}", "{time}", "{TIME-1}", "{time-1}"], date("H:i:s", time()), $text);
            $text = str_replace(["{TIME-2}", "{time-2}"], date("H:i", time()), $text);
            return str_replace(["{TIME-3}", "{time-3}"], "<t:".time().":f>", $text); //TODO Other formatted times supported by discord.
        };

        $embeds = [];
        $embed_config = $config["format"]["embed"];
        if($embed_config["enabled"]){
            $fields = [];
            foreach($embed_config["fields"] as $field){
                $fields[] = new Field($formatter($field["name"]), $formatter($field["value"]), $field["inline"]);
            }
            $embeds[] = new Embed(($embed_config["title"] === null ? null : $formatter($embed_config["title"])),
                ($embed_config["description"] === null ? null : $formatter($embed_config["description"])),
                $embed_config["url"], $embed_config["time"] ? time() : null, $embed_config["colour"],
                $embed_config["footer"] === null ? null : new Footer($formatter($embed_config["footer"])), null,
                null, null, null, $embed_config["author"] === null ? null : new Author($formatter($embed_config["author"])), $fields);
        }

        foreach($config["to_discord_channels"] as $cid){
            $this->plugin->getDiscord()->getApi()->sendMessage(null, $cid, $formatter($config["format"]["text"]??""), null, $embeds)->otherwise(function(ApiRejection $rejection){
                $this->plugin->getLogger()->warning("Failed to send discord message on minecraft message event, '{$rejection->getMessage()}'");
            });
        }
    }

    public function onMinecraftCommand(CommandEvent $event): void{
        if(!$this->ready){
            //Unlikely to happen, discord will most likely be ready before anyone even joins.
            $this->plugin->getLogger()->debug("Ignoring command event, discord is not ready.");
            return;
        }

        /** @var array $config */
        $config = $this->config["commands"]["minecraft"];
        if(!$config['enabled']){
            return;
        }
        $player = $event->getSender();
        if(!$player instanceof Player){
            return;
        }

        $message = $event->getCommand();
        $args = explode(" ", $message);
        $command = array_shift($args);
        $world = $player->getWorld()->getDisplayName();

        $from_worlds = is_array($config["from_worlds"]) ? $config["from_worlds"] : [$config["from_worlds"]];
        if(!in_array("*", $from_worlds)){
            //Only specific worlds.
            if(!in_array($world, $from_worlds)){
                $this->plugin->getLogger()->debug("Ignoring command event, world '$world' is not listed.");
                return;
            }
        }

        $formatter = function(string $text) use ($player, $message, $world, $command, $args): string{
            $text = str_replace(["{USERNAME}", "{username}", "{PLAYER}", "{player}"], $player->getName(), $text);
            $text = str_replace(["{DISPLAYNAME}", "{displayname}", "{DISPLAY_NAME}", "{display_name}", "{NICKNAME}", "{nickname}"], $player->getDisplayName(), $text);
            $text = str_replace(["{MESSAGE}", "{message}"], $message, $text);
            $text = str_replace(["{COMMAND}", "{command}"], $command, $text);
            $text = str_replace(["{ARGS}", "{args}"], implode(" ", $args), $text);
            $text = str_replace(["{XUID}", "{xuid}"], $player->getXuid(), $text);
            $text = str_replace(["{UUID}", "{uuid}"], $player->getUniqueId()->toString(), $text);
            $text = str_replace(["{ADDRESS}", "{address}", "{IP}", "{ip}"], $player->getNetworkSession()->getIp(), $text);
            $text = str_replace(["{PORT}", "{port}"], strval($player->getNetworkSession()->getPort()), $text);
            $text = str_replace(["{WORLD}", "{world}", "{LEVEL}", "{level}"], $world, $text);
            $text = str_replace(["{TIME}", "{time}", "{TIME-1}", "{time-1}"], date("H:i:s", time()), $text);
            $text = str_replace(["{TIME-2}", "{time-2}"], date("H:i", time()), $text);
            return str_replace(["{TIME-3}", "{time-3}"], "<t:".time().":f>", $text); //TODO Other formatted times supported by discord.
        };

        $embeds = [];
        $embed_config = $config["format"]["embed"];
        if($embed_config["enabled"]){
            $fields = [];
            foreach($embed_config["fields"] as $field){
                $fields[] = new Field($formatter($field["name"]), $formatter($field["value"]), $field["inline"]);
            }
            $embeds[] = new Embed(($embed_config["title"] === null ? null : $formatter($embed_config["title"])),
                ($embed_config["description"] === null ? null : $formatter($embed_config["description"])),
                $embed_config["url"], $embed_config["time"] ? time() : null, $embed_config["colour"],
                $embed_config["footer"] === null ? null : new Footer($formatter($embed_config["footer"])), null,
                null, null, null, $embed_config["author"] === null ? null : new Author($formatter($embed_config["author"])), $fields);
        }

        foreach($config["to_discord_channels"] as $cid){
            $this->plugin->getDiscord()->getApi()->sendMessage(null, $cid, $formatter($config["format"]["text"]??""), null, $embeds)->otherwise(function(ApiRejection $rejection){
                $this->plugin->getLogger()->warning("Failed to send discord message on minecraft command event, '{$rejection->getMessage()}'");
            });
        }
    }

    public function onMinecraftJoin(PlayerJoinEvent $event): void{
        if(!$this->ready){
            //Unlikely to happen, discord will most likely be ready before anyone even joins.
            $this->plugin->getLogger()->debug("Ignoring join event, discord is not ready.");
            return;
        }

        /** @var array $config */
        $config = $this->config["join"]["minecraft"];
        if(!$config['enabled']){
            return;
        }

        $player = $event->getPlayer();

        $formatter = function(string $text) use ($player): string{
            $text = str_replace(["{USERNAME}", "{username}", "{PLAYER}", "{player}"], $player->getName(), $text);
            $text = str_replace(["{DISPLAYNAME}", "{displayname}", "{DISPLAY_NAME}", "{display_name}", "{NICKNAME}", "{nickname}"], $player->getDisplayName(), $text);
            $text = str_replace(["{XUID}", "{xuid}"], $player->getXuid(), $text);
            $text = str_replace(["{UUID}", "{uuid}"], $player->getUniqueId()->toString(), $text);
            $text = str_replace(["{ADDRESS}", "{address}", "{IP}", "{ip}"], $player->getNetworkSession()->getIp(), $text);
            $text = str_replace(["{PORT}", "{port}"], strval($player->getNetworkSession()->getPort()), $text);
            $text = str_replace(["{TIME}", "{time}", "{TIME-1}", "{time-1}"], date("H:i:s", time()), $text);
            $text = str_replace(["{TIME-2}", "{time-2}"], date("H:i", time()), $text);
            return str_replace(["{TIME-3}", "{time-3}"], "<t:".time().":f>", $text); //TODO Other formatted times supported by discord.
        };

        $embeds = [];
        $embed_config = $config["format"]["embed"];
        if($embed_config["enabled"]){
            $fields = [];
            foreach($embed_config["fields"] as $field){
                $fields[] = new Field($formatter($field["name"]), $formatter($field["value"]), $field["inline"]);
            }
            $embeds[] = new Embed(($embed_config["title"] === null ? null : $formatter($embed_config["title"])),
                ($embed_config["description"] === null ? null : $formatter($embed_config["description"])),
                $embed_config["url"], $embed_config["time"] ? time() : null, $embed_config["colour"],
                $embed_config["footer"] === null ? null : new Footer($formatter($embed_config["footer"])), null,
                null, null, null, $embed_config["author"] === null ? null : new Author($formatter($embed_config["author"])), $fields);
        }

        foreach($config["to_discord_channels"] as $cid){
            $this->plugin->getDiscord()->getApi()->sendMessage(null, $cid, $formatter($config["format"]["text"]??""), null, $embeds)->otherwise(function(ApiRejection $rejection){
                $this->plugin->getLogger()->warning("Failed to send discord message on minecraft join event, '{$rejection->getMessage()}'");
            });
        }
    }

    public function onMinecraftLeave(PlayerQuitEvent $event): void{
        if(!$this->ready){
            //Unlikely to happen, discord will most likely be ready before anyone even joins.
            $this->plugin->getLogger()->debug("Ignoring leave event, discord is not ready.");
            return;
        }

        /** @var array $config */
        $config = $this->config["leave"]["minecraft"];
        if(!$config['enabled']){
            return;
        }

        $player = $event->getPlayer();
        $reason = $event->getQuitReason();
        if($reason instanceof Translatable){
            $reason = $reason->getText();
        }
        if($reason === "transfer" and $config["ignore_transferred"]){
            return;
        }

        $formatter = function(string $text) use ($player, $reason): string{
            $text = str_replace(["{USERNAME}", "{username}", "{PLAYER}", "{player}"], $player->getName(), $text);
            $text = str_replace(["{DISPLAYNAME}", "{displayname}", "{DISPLAY_NAME}", "{display_name}", "{NICKNAME}", "{nickname}"], $player->getDisplayName(), $text);
            $text = str_replace(["{XUID}", "{xuid}"], $player->getXuid(), $text);
            $text = str_replace(["{UUID}", "{uuid}"], $player->getUniqueId()->toString(), $text);
            $text = str_replace(["{ADDRESS}", "{address}", "{IP}", "{ip}"], $player->getNetworkSession()->getIp(), $text);
            $text = str_replace(["{PORT}", "{port}"], strval($player->getNetworkSession()->getPort()), $text);
            $text = str_replace(["{REASON}", "{reason}"], $reason, $text);
            $text = str_replace(["{TIME}", "{time}", "{TIME-1}", "{time-1}"], date("H:i:s", time()), $text);
            $text = str_replace(["{TIME-2}", "{time-2}"], date("H:i", time()), $text);
            return str_replace(["{TIME-3}", "{time-3}"], "<t:".time().":f>", $text); //TODO Other formatted times supported by discord.
        };

        $embeds = [];
        $embed_config = $config["format"]["embed"];
        if($embed_config["enabled"]){
            $fields = [];
            foreach($embed_config["fields"] as $field){
                $fields[] = new Field($formatter($field["name"]), $formatter($field["value"]), $field["inline"]);
            }
            $embeds[] = new Embed(($embed_config["title"] === null ? null : $formatter($embed_config["title"])),
                ($embed_config["description"] === null ? null : $formatter($embed_config["description"])),
                $embed_config["url"], $embed_config["time"] ? time() : null, $embed_config["colour"],
                $embed_config["footer"] === null ? null : new Footer($formatter($embed_config["footer"])), null,
                null, null, null, $embed_config["author"] === null ? null : new Author($formatter($embed_config["author"])), $fields);
        }

        foreach($config["to_discord_channels"] as $cid){
            $this->plugin->getDiscord()->getApi()->sendMessage(null, $cid, $formatter($config["format"]["text"]??""), null, $embeds)->otherwise(function(ApiRejection $rejection){
                $this->plugin->getLogger()->warning("Failed to send discord message on minecraft leave event, '{$rejection->getMessage()}'");
            });
        }
    }

    public function onMinecraftTransferred(PlayerTransferEvent $event): void{
        if(!$this->ready){
            //Unlikely to happen, discord will most likely be ready before anyone even joins.
            $this->plugin->getLogger()->debug("Ignoring transfer event, discord is not ready.");
            return;
        }

        /** @var array $config */
        $config = $this->config["transferred"]["minecraft"];
        if(!$config['enabled']){
            return;
        }

        $player = $event->getPlayer();
        $address = $event->getAddress();
        $port = $event->getPort();

        $formatter = function(string $text) use ($player, $address, $port): string{
            $text = str_replace(["{USERNAME}", "{username}", "{PLAYER}", "{player}"], $player->getName(), $text);
            $text = str_replace(["{DISPLAYNAME}", "{displayname}", "{DISPLAY_NAME}", "{display_name}", "{NICKNAME}", "{nickname}"], $player->getDisplayName(), $text);
            $text = str_replace(["{XUID}", "{xuid}"], $player->getXuid(), $text);
            $text = str_replace(["{UUID}", "{uuid}"], $player->getUniqueId()->toString(), $text);
            $text = str_replace(["{ADDRESS}", "{address}", "{IP}", "{ip}"], $player->getNetworkSession()->getIp(), $text);
            $text = str_replace(["{PORT}", "{port}"], strval($player->getNetworkSession()->getPort()), $text);
            $text = str_replace(["{SERVER_ADDRESS}", "{server_address}"], $address, $text);
            $text = str_replace(["{SERVER_PORT}", "{server_port}"], strval($port), $text);
            $text = str_replace(["{TIME}", "{time}", "{TIME-1}", "{time-1}"], date("H:i:s", time()), $text);
            $text = str_replace(["{TIME-2}", "{time-2}"], date("H:i", time()), $text);
            return str_replace(["{TIME-3}", "{time-3}"], "<t:".time().":f>", $text); //TODO Other formatted times supported by discord.
        };

        $embeds = [];
        $embed_config = $config["format"]["embed"];
        if($embed_config["enabled"]){
            $fields = [];
            foreach($embed_config["fields"] as $field){
                $fields[] = new Field($formatter($field["name"]), $formatter($field["value"]), $field["inline"]);
            }
            $embeds[] = new Embed(($embed_config["title"] === null ? null : $formatter($embed_config["title"])),
                ($embed_config["description"] === null ? null : $formatter($embed_config["description"])),
                $embed_config["url"], $embed_config["time"] ? time() : null, $embed_config["colour"],
                $embed_config["footer"] === null ? null : new Footer($formatter($embed_config["footer"])), null,
                null, null, null, $embed_config["author"] === null ? null : new Author($formatter($embed_config["author"])), $fields);
        }

        foreach($config["to_discord_channels"] as $cid){
            $this->plugin->getDiscord()->getApi()->sendMessage(null, $cid, $formatter($config["format"]["text"]??""), null, $embeds)->otherwise(function(ApiRejection $rejection){
                $this->plugin->getLogger()->warning("Failed to send discord message on minecraft transfer event, '{$rejection->getMessage()}'");
            });
        }
    }

    //--- Discord Events -> Minecraft Server ---//

    /**
     * @priority MONITOR
     */
    public function onDiscordMemberJoin(MemberJoined $event): void{
        /** @var array $config */
        $config = $this->config["join"]["discord"];
        if(!$config['enabled']){
            return;
        }

        if(!in_array($event->getMember()->getGuildId(), $config["servers"])){
            $this->plugin->getLogger()->debug("Ignoring discord member join event, discord guild '".$event->getMember()->getGuildId()."' is not in config list.");
            return;
        }

        $this->api->fetchGuild($event->getMember()->getGuildId())->then(function(ApiResolution $res) use($event, $config){
            /** @var Guild $server */
            $server = $res->getData()[0];
            $this->api->fetchUser($event->getMember()->getUserId())->then(function(ApiResolution $res) use($server, $event, $config){
                /** @var User $user */
                $user = $res->getData()[0];
                $member = $event->getMember();

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
            }, function(ApiRejection $rej) use($event){
                $this->plugin->getLogger()->warning("Failed to process discord member join event, failed to fetch user '".$event->getMember()->getUserId()."' - ".$rej->getMessage());
            });
        }, function(ApiRejection $rej) use($event){
            $this->plugin->getLogger()->warning("Failed to process discord member join event, failed to fetch guild '".$event->getMember()->getGuildId()."' - ".$rej->getMessage());
        });
    }

    /**
     * Who doesn't like some copy/paste?
     * @priority MONITOR
     */
    public function onDiscordMemberLeave(MemberLeft $event): void{
        /** @var array $config */
        $config = $this->config["leave"]["discord"];
        if(!$config['enabled']){
            return;
        }

        if(!in_array($event->getGuildId(), $config["servers"])){
            $this->plugin->getLogger()->debug("Ignoring discord member leave event, discord guild '".$event->getGuildId()."' is not in config list.");
            return;
        }

        $this->api->fetchGuild($event->getGuildId())->then(function(ApiResolution $res) use($config, $event){
            /** @var Guild $server */
            $server = $res->getData()[0];
            $this->api->fetchUser($event->getUserId())->then(function(ApiResolution $res) use($server, $event, $config){
                /** @var User $user */
                $user = $res->getData()[0];
                $member = $event->getCachedMember();
                if($member === null){
                    $this->api->fetchMember($event->getGuildId(), $event->getUserId())->then(function(ApiResolution $res) use ($server, $user, $config){
                        /** @var Member $member */
                        $member = $res->getData()[0];

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
                    }, function(ApiRejection $rej) use ($event){
                        $this->plugin->getLogger()->warning("Failed to process discord member leave event, failed to fetch member '".$event->getUserId()."' - ".$rej->getMessage());
                    });
                }else{
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
            }, function(ApiRejection $rej) use($event){
                $this->plugin->getLogger()->warning("Failed to process discord member leave event, failed to fetch user '".$event->getUserId()."' - ".$rej->getMessage());
            });
        }, function(ApiRejection $rej) use($event){
            $this->plugin->getLogger()->warning("Failed to process discord member leave event, failed to fetch guild '".$event->getGuildId()."' - ".$rej->getMessage());
        });
    }

    /**
     * @priority MONITOR
     */
    public function onDiscordMessage(MessageSent $event): void{
        /** @var array $config */
        $config = $this->config["messages"]["discord"];
        if(!$config['enabled']){
            return;
        }
        if(!in_array(($msg = $event->getMessage())->getType(), [MessageType::DEFAULT, MessageType::CHAT_INPUT_COMMAND, MessageType::REPLY])){
            $this->plugin->getLogger()->debug("Ignoring message '{$msg->getId()}', not a valid type.");
            return;
        }

        if(!in_array($msg->getChannelId(), $config['from_channels'])){
            $this->plugin->getLogger()->debug("Ignoring message from channel '{$msg->getChannelId()}', ID is not in list.");
            return;
        }

        if($msg->getAuthorId() === null){
            $this->plugin->getLogger()->debug("Ignoring message '{$msg->getId()}', no author ID.");
            return;
        }

        if(strlen(trim($msg->getContent()??"")) === 0){
            //Files or other type of messages.
            $this->plugin->getLogger()->debug("Ignoring message '{$msg->getId()}', No text content.");
            return;
        }

        $this->api->fetchChannel(null, $msg->getChannelId())->then(function(ApiResolution $res) use($msg, $config){
            /** @var Channel $channel */
            $channel = $res->getData()[0];
            if($channel->getGuildId() === null){
                $this->plugin->getLogger()->debug("Ignoring message '{$msg->getId()}', channel is not in a guild.");
                return;
            }
            $this->api->fetchGuild($channel->getGuildId())->then(function(ApiResolution $res) use ($channel, $msg, $config){
                /** @var Guild $server */
                $server = $res->getData()[0];
                $this->api->fetchUser($msg->getAuthorId())->then(function(ApiResolution $res) use ($server, $channel, $msg, $config){
                    /** @var User $user */
                    $user = $res->getData()[0];
                    $this->api->fetchMember($channel->getGuildId(), $user->getId())->then(function(ApiResolution $res) use ($server, $user, $channel, $msg, $config){
                        /** @var Member $member */
                        $member = $res->getData()[0];

                        //Format message.
                        $message = str_replace(['{NICKNAME}', '{nickname}'], $member->getNickname()??$user->getUsername(), $config['format']);
                        $message = str_replace(['{USERNAME}', '{username}'], $user->getUsername(), $message);
                        $message = str_replace(['{USER_DISCRIMINATOR}', '{user_discriminator}', '{DISCRIMINATOR}', '{discriminator}'], $user->getDiscriminator(), $message);
                        $message = str_replace(['{MESSAGE}', '{message'], trim($msg->getContent()??""), $message);
                        $message = str_replace(['{SERVER}', '{server}'], $server->getName(), $message);
                        $message = str_replace(['{CHANNEL}', '{channel}'], $channel->getName()??"Unknown", $message);
                        $message = str_replace(['{TIME}', '{time}', '{TIME-1}', '{time-1}'], date('G:i:s', $msg->getTimestamp()), $message);
                        $message = str_replace(['{TIME-2}', '{time-2}'], date('G:i', $msg->getTimestamp()), $message);
                        if(!is_string($message)){
                            throw new AssertionError("A string is always expected, got '".gettype($message)."'");
                        }

                        //Broadcast.
                        $this->plugin->getServer()->broadcastMessage($message, $this->getPlayersInWorlds($config['to_minecraft_worlds']));
                    }, function(ApiRejection $rej) use ($msg){
                        $this->plugin->getLogger()->warning("Failed to process discord message event, failed to fetch member '".$msg->getAuthorId()."' - ".$rej->getMessage());
                    });
                }, function(ApiRejection $rej) use ($msg){
                    $this->plugin->getLogger()->warning("Failed to process discord message event, failed to fetch user '".$msg->getAuthorId()."' - ".$rej->getMessage());
                });
            }, function(ApiRejection $rej) use ($channel){
                $this->plugin->getLogger()->warning("Failed to process discord message event, failed to fetch guild '".$channel->getGuildId()."' - ".$rej->getMessage());
            });
        }, function(ApiRejection $rej) use ($msg){
            $this->plugin->getLogger()->warning("Failed to process discord message event, failed to fetch channel '".$msg->getChannelId()."' - ".$rej->getMessage());
        });
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
                $world = $this->plugin->getServer()->getWorldManager()->getWorldByName($world);
                if($world === null){
                    $this->plugin->getLogger()->warning("World '$world' specified in config.yml does not exist.");
                }else{
                    $players = array_merge($players, $world->getPlayers());
                }
            }
        }
        return $players;
    }
}