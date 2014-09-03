<?php

/**
 * ChestShop
 * @version 2.0.0
 * @author MinecrafterJPN
 */

namespace ChestShop;

use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;

class ChestShop extends PluginBase
{
    private $database;

    public function onLoad()
    {
    }

    public function onEnable()
    {
        $this->database = new \SQLite3($this->getDataFolder()."ChestShop.sqlite3");
        $this->getServer()->getPluginManager()->registerEvents(new EventListener($this), $this);
        $sql = "CREATE TABLE IF NOT EXISTS ChestShop(
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    shopOwner TEXT NOT NULL,
                    saleNum INTEGER NOT NULL,
                    price INTEGER NOT NULL,
                    productID INTEGER NOT NULL,
                    productMeta INTEGER NOT NULL,
                    signX INTEGER NOT NULL,
                    signY INTEGER NOT NULL,
                    signZ INTEGER NOT NULL,
                    chestX INTEGER NOT NULL,
                    chestY INTEGER NOT NULL,
                    chestZ INTEGER NOT NULL
		)";
        $this->database->exec($sql);
    }

    public function onDisable()
    {
    }

    public function onCommand(CommandSender $sender, Command $command, $label, array $args)
    {
        switch ($command->getName()) {
            case "id":
                $name = array_shift($args);
                //id検索
                return true;

            default:
                return false;
        }
    }
}










