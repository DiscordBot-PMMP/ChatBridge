<?php /** @noinspection PhpUnusedPrivateMethodInspection */

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

use JaxkDev\DiscordBot\Plugin\Utils;

abstract class ConfigUtils{

    const VERSION = 1;

    // Map all versions to a static function.
    private const _PATCH_MAP = [];

    static public function update(array &$config): void{
        for($i = (int)$config["version"]; $i < self::VERSION; $i += 1){
            /** @phpstan-ignore-next-line */
            $config = forward_static_call([self::class, self::_PATCH_MAP[$i]], $config);
        }
    }

    /**
     * Verifies the config's keys and values, returning any keys and a relevant message.
     * @param array $config
     * @return string[]
     */
    static public function verify(array $config): array{
        $result = [];
        $keys = ["version", "messages", "commands", "leave", "join", "transferred"];
        foreach(array_keys($config) as $event){
            if(!in_array($event, $keys)){
                //Event key is invalid.
                $result[] = "Invalid key '$event' found.";
                continue;
            }
            $keys = array_diff($keys, [$event]);
            switch($event){
                case "version":
                    if($config[$event] !== self::VERSION){
                        //Should never happen.
                        $result[] = "Invalid version '$event' found.";
                    }
                    break;
                case "messages":
                    $result = array_merge($result, self::verify_discord($event, $config));
                    if(!isset($config[$event]["discord"]["from_channels"])){
                        $result[] = "Missing key '".$event.".discord.from_channels'";
                    }elseif(!is_array($config[$event]["discord"]["from_channels"])){
                        $result[] = "Invalid value for key '".$event.".discord.from_channels', expected array of channel ID's.";
                    }else{
                        foreach($config[$event]["discord"]["from_channels"] as $cid){
                            if(!Utils::validDiscordSnowflake($cid)){
                                $result[] = "Invalid channel ID '$cid' found in key '".$event.".discord.from_channels'.";
                            }
                        }
                    }
                    //TODO Minecraft key
                    break;
                case "commands":
                    //TODO Minecraft key
                    break;
                case "join":
                case "leave":
                    $result = array_merge($result, self::verify_discord($event, $config));
                    if(!isset($config[$event]["discord"]["servers"])){
                        $result[] = "Missing key '".$event.".discord.servers'";
                    }elseif(!is_array($config[$event]["discord"]["servers"])){
                        $result[] = "Invalid value for key '".$event.".discord.servers', expected array of server ID's.";
                    }else{
                        foreach($config[$event]["discord"]["servers"] as $sid){
                            if(!Utils::validDiscordSnowflake($sid)){
                                $result[] = "Invalid server ID '$sid' found in key '".$event.".discord.servers'.";
                            }
                        }
                    }
                    //TODO Minecraft key
                    break;
                case "transferred":
                    //TODO Minecraft key
                    break;
                default:
                    $result[] = "Unknown key '$event' found.";
                    break;
            }
        }

        if(sizeof($keys) !== 0){
            $result[] = "Missing keys: '" . implode("', '", $keys)."'";
        }

        return $result;
    }

    /**
     * Verifies the config's generic discord section.
     * @param string $event
     * @param array $config
     * @return string[]
     */
    static private function verify_discord(string $event, array $config): array{
        $result = [];
        $config = $config[$event]["discord"];
        if(!isset($config["enabled"])){
            $result[] = "Missing key: '". $event . ".discord.enabled'";
        }elseif(!is_bool($config["enabled"])){
            $result[] = "Invalid value for key: '". $event . ".discord.enabled', expected boolean (true/false).";
        }
        if(!isset($config["format"])){
            $result[] = "Missing key: '".$event.".discord.format'";
        }elseif(!is_string($config["format"])){
            $result[] = "Invalid value for key: '".$event.".discord.format', expected string.";
        }
        if(!isset($config["to_minecraft_worlds"])){
            $result[] = "Missing key: '".$event.".discord.to_minecraft_worlds'";
        }elseif(!is_array($config["to_minecraft_worlds"]) and !is_string($config["to_minecraft_worlds"])){
            $result[] = "Invalid value for key: '".$event.".discord.to_minecraft_worlds', expected array or string.";
        }
        return $result;
    }
}