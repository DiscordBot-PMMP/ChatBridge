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
        $keys = ["version", "presence", "messages", "commands", "leave", "join", "transferred"];
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
                case "presence":
                    if(!is_array($config[$event])){
                        $result[] = "Invalid value for '$event' found, array expected.";
                    }else{
                        if(!isset($config[$event]["status"])){
                            $result[] = "Missing 'status' key for '$event' found.";
                        }elseif(!in_array(strtolower($config[$event]["status"]), ["online", "idle", "dnd", "offline"])){
                            $result[] = "Invalid value for 'status' key for '$event' found, one of online,idle,dnd,offline expected.";
                        }
                        if(!isset($config[$event]["type"])){
                            $result[] = "Missing 'type' key for '$event' found.";
                        }elseif(!in_array(strtolower($config[$event]["type"]), ["playing", "play", "listening", "listen", "watching", "watch"])){
                            $result[] = "Invalid value for 'type' key for '$event' found, one of playing,listening,watching expected.";
                        }
                        if(!isset($config[$event]["message"])){
                            $result[] = "Missing 'message' key for '$event' found.";
                        }elseif(!is_string($config[$event]["message"])){
                            $result[] = "Invalid value for 'message' key for '$event' found, string expected.";
                        }
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
                    $result = array_merge($result, self::verify_minecraft($event, $config));
                    if(!isset($config[$event]["minecraft"]["from_worlds"])){
                        $result[] = "Missing key '".$event.".minecraft.from_worlds'";
                    }elseif(!is_string($config[$event]["minecraft"]["from_worlds"]) and !is_array($config[$event]["minecraft"]["from_worlds"])){
                        $result[] = "Invalid value for key '".$event.".minecraft.from_worlds', expected array or string.";
                    }
                    if(!isset($config[$event]["minecraft"]["escapes"])){
                        $result[] = "Missing key '".$event.".minecraft.escapes'";
                    }
                    break;
                case "join":
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
                    $result = array_merge($result, self::verify_minecraft($event, $config));
                    break;
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
                    $result = array_merge($result, self::verify_minecraft($event, $config));
                    if(!isset($config[$event]["minecraft"]["ignore_transferred"])){
                        $result[] = "Missing key '".$event.".minecraft.ignore_transferred'";
                    }elseif(!is_bool($config[$event]["minecraft"]["ignore_transferred"])){
                        $result[] = "Invalid value for key '".$event.".minecraft.ignore_transferred', expected boolean.";
                    }
                    break;
                case "commands":
                case "transferred":
                    $result = array_merge($result, self::verify_minecraft($event, $config));
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

    /**
     * Verifies the config's generic minecraft section.
     * @param string $event
     * @param array $config
     * @return string[]
     */
    static private function verify_minecraft(string $event, array $config): array{
        $result = [];
        $config = $config[$event]["minecraft"];
        if(!isset($config["enabled"])){
            $result[] = "Missing key: '". $event . ".minecraft.enabled'";
        }elseif(!is_bool($config["enabled"])){
            $result[] = "Invalid value for key: '". $event . ".minecraft.enabled', expected boolean (true/false).";
        }
        if(!isset($config["format"])){
            $result[] = "Missing key: '".$event.".discord.format'";
        }elseif(!is_array($config["format"])){
            $result[] = "Invalid value for key: '".$event.".discord.format', expected array.";
        }else{
            //format
            if(!isset($config["format"]["text"])){
                $result[] = "Missing key: '".$event.".minecraft.format.text'";
            }elseif(!is_string($config["format"]["text"])){
                $result[] = "Invalid value for key: '".$event.".minecraft.format.text', expected string.";
            }elseif(strlen($config["format"]["text"]) > 2000){
                $result[] = "Invalid value for key: '".$event.".minecraft.format.text', string is too long (max 2000 characters).";
            }
            if(!isset($config["format"]["embed"])){
                $result[] = "Missing key: '".$event.".minecraft.format.embed'";
            }elseif(!is_array($config["format"]["embed"])){
                $result[] = "Invalid value for key: '".$event.".minecraft.format.embed', expected array.";
            }else{
                //embed
                if(!isset($config["format"]["embed"]["enabled"])){
                    $result[] = "Missing key: '".$event.".minecraft.format.embed.enabled'";
                }elseif(!is_bool($config["format"]["embed"]["enabled"])){
                    $result[] = "Invalid value for key: '".$event.".minecraft.format.embed.enabled', expected boolean (true/false).";
                }
                if(!array_key_exists("title", $config["format"]["embed"])){
                    $result[] = "Missing key: '".$event.".minecraft.format.embed.title'";
                }elseif(!is_string($config["format"]["embed"]["title"]) and !is_null($config["format"]["embed"]["title"])){
                    $result[] = "Invalid value for key: '".$event.".minecraft.format.embed.title', expected string or null.";
                }elseif(strlen($config["format"]["embed"]["title"]??"") > 2048){
                    $result[] = "Invalid value for key: '".$event.".minecraft.format.embed.title', string is too long (max 2048 characters).";
                }
                if(!array_key_exists("description", $config["format"]["embed"])){
                    $result[] = "Missing key: '".$event.".minecraft.format.embed.description'";
                }elseif(!is_string($config["format"]["embed"]["description"]) and !is_null($config["format"]["embed"]["description"])){
                    $result[] = "Invalid value for key: '".$event.".minecraft.format.embed.description', expected string or null.";
                }elseif(strlen($config["format"]["embed"]["description"]??"") > 4096){
                    $result[] = "Invalid value for key: '".$event.".minecraft.format.embed.description', string is too long (max 4096 characters).";
                }
                if(!array_key_exists("author", $config["format"]["embed"])){
                    $result[] = "Missing key: '".$event.".minecraft.format.embed.author'";
                }elseif(!is_string($config["format"]["embed"]["author"]) and !is_null($config["format"]["embed"]["author"])){
                    $result[] = "Invalid value for key: '".$event.".minecraft.format.embed.author', expected string or null.";
                }elseif(strlen($config["format"]["embed"]["description"]??"") > 2048){
                    $result[] = "Invalid value for key: '".$event.".minecraft.format.embed.author', string is too long (max 2048 characters).";
                }
                if(!array_key_exists("url", $config["format"]["embed"])){
                    $result[] = "Missing key: '".$event.".minecraft.format.embed.url'";
                }elseif(!is_string($config["format"]["embed"]["url"]) and !is_null($config["format"]["embed"]["url"])){
                    $result[] = "Invalid value for key: '".$event.".minecraft.format.embed.url', expected string or null.";
                }
                if(!array_key_exists("footer", $config["format"]["embed"])){
                    $result[] = "Missing key: '".$event.".minecraft.format.embed.footer'";
                }elseif(!is_string($config["format"]["embed"]["footer"]) and !is_null($config["format"]["embed"]["footer"])){
                    $result[] = "Invalid value for key: '".$event.".minecraft.format.embed.footer', expected string or null.";
                }elseif(strlen($config["format"]["embed"]["footer"]??"") > 2048){
                    $result[] = "Invalid value for key: '".$event.".minecraft.format.embed.footer', string is too long (max 2048 characters).";
                }
                if(!isset($config["format"]["embed"]["time"])){
                    $result[] = "Missing key: '".$event.".minecraft.format.embed.time'";
                }elseif(!is_bool($config["format"]["embed"]["time"])){
                    $result[] = "Invalid value for key: '".$event.".minecraft.format.embed.time', expected bool (true/false).";
                }
                if(!isset($config["format"]["embed"]["colour"])){
                    $result[] = "Missing key: '".$event.".minecraft.format.embed.colour'";
                }elseif(!is_int($config["format"]["embed"]["colour"])){
                    $result[] = "Invalid value for key: '".$event.".minecraft.format.embed.colour', expected hex colour (eg 0xFFFFFF).";
                }elseif($config["format"]["embed"]["colour"] < 0 or $config["format"]["embed"]["colour"] > 0xFFFFFF){
                    $result[] = "Invalid value for key: '".$event.".minecraft.format.embed.colour', expected hex colour 0x000000 - 0xFFFFFF.";
                }
                if(!isset($config["format"]["embed"]["fields"])){
                    $result[] = "Missing key: '".$event.".minecraft.format.embed.fields'";
                }elseif(!is_array($config["format"]["embed"]["fields"])){
                    $result[] = "Invalid value for key: '".$event.".minecraft.format.embed.fields', expected array (put '[]' for no fields).";
                }else{
                    //Check fields
                    foreach($config["format"]["embed"]["fields"] as $field){
                        if(!isset($field["name"])){
                            $result[] = "Missing key: '".$event.".minecraft.format.embed.fields.name'";
                        }elseif(!is_string($field["name"])){
                            $result[] = "Invalid value for key: '".$event.".minecraft.format.embed.fields.name', expected string.";
                        }elseif(strlen($field["name"]) > 256){
                            $result[] = "Invalid value for key: '".$event.".minecraft.format.embed.fields.name', string is too long (max 256 characters).";
                        }
                        if(!isset($field["value"])){
                            $result[] = "Missing key: '".$event.".minecraft.format.embed.fields.value'";
                        }elseif(!is_string($field["value"])){
                            $result[] = "Invalid value for key: '".$event.".minecraft.format.embed.fields.value', expected string.";
                        }elseif(strlen($field["value"]) > 2048){
                            $result[] = "Invalid value for key: '".$event.".minecraft.format.embed.fields.value', string is too long (max 2048 characters).";
                        }
                        if(!isset($field["inline"])){
                            $result[] = "Missing key: '".$event.".minecraft.format.embed.fields.inline'";
                        }elseif(!is_bool($field["inline"])){
                            $result[] = "Invalid value for key: '".$event.".minecraft.format.embed.fields.inline', expected bool (true/false).";
                        }
                    }
                }
            }
        }
        if(!isset($config["to_discord_channels"])){
            $result[] = "Missing key: '".$event.".minecraft.to_discord_channels'";
        }elseif(!is_array($config["to_discord_channels"]) and !is_string($config["to_discord_channels"])){
            $result[] = "Invalid value for key: '".$event.".minecraft.to_discord_channels', expected array or string.";
        }else{
            foreach((is_array($config["to_discord_channels"]) ? $config["to_discord_channels"] : [$config["to_discord_channels"]]) as $cid){
                if(!Utils::validDiscordSnowflake($cid)){
                    $result[] = "Invalid channel ID '$cid' found in key '".$event.".minecraft.to_discord_channels'.";
                }
            }
        }
        return $result;
    }
}