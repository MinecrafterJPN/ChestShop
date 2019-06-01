<?php
declare(strict_types=1);
namespace ChestShop;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\item\ItemIds;
use pocketmine\plugin\PluginBase;

class ChestShop extends PluginBase
{
    public function onEnable()
    {
        $this->getServer()->getPluginManager()->registerEvents(new EventListener($this, new DatabaseManager($this->getDataFolder() . 'ChestShop.sqlite3')), $this);
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool
    {
        switch ($command->getName()) {
            case "id":
                $name = array_shift($args);
                $constants = array_keys((new \ReflectionClass(ItemIds::class))->getConstants());
                foreach ($constants as $constant) {
                    if (stripos($constant, $name) !== false) {
                        $id = constant(ItemIds::class."::$constant");
                        $constant = str_replace("_", " ", $constant);
                        $sender->sendMessage("ID:$id $constant");
                    }
                }
                return true;

            default:
                return false;
        }
    }
}