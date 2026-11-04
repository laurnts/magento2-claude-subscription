<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\ClaudeSubscription\Setup\Patch\Data;

use MagoAssistant\ClaudeSubscription\Setup\ServiceCodeRenamer;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Renames saved Claude provider rows and drops the old module record.
 */
class RenameClaudeMaxServiceCode implements DataPatchInterface
{
    private const CONFIG_PATH = 'mageos_ai/services/configuration';

    /**
     * @param ResourceConnection $resource
     * @param ServiceCodeRenamer $renamer
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ServiceCodeRenamer $renamer,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function apply()
    {
        $connection = $this->resource->getConnection();
        $config = $this->resource->getTableName('core_config_data');
        $select = $connection->select()
            ->from($config, ['config_id', 'value'])
            ->where('path = ?', self::CONFIG_PATH);
        foreach ($connection->fetchAll($select) as $row) {
            $renamed = $this->renamer->rename((string) ($row['value'] ?? ''));
            if ($renamed === null) {
                continue;
            }
            $connection->update(
                $config,
                ['value' => $renamed],
                ['config_id = ?' => $row['config_id']]
            );
        }
        $connection->delete(
            $this->resource->getTableName('setup_module'),
            ['module = ?' => 'MagoAssistant_ClaudeMax']
        );
        return $this;
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
