<?php

namespace ChestShop;


use pocketmine\event\Listener;
use pocketmine\item\Item;

class EventListener implements Listener
{
    private $plugin;

    public function __construct(ChestShop $plugin)
    {
        $this->plugin = $plugin;
    }

    public function onTileUpdate()
    {

    }

    public function onPlayerPlaceBlock()
    {

    }

    private function isItem($id)
    {
        if (isset(Item::$list[$id])) return true;
        return false;
    }
} 