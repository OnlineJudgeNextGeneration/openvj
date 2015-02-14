<?php
/**
 * This file is part of openvj project.
 *
 * Copyright 2013-2015 openvj dev team.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace VJ\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use VJ\Core\Application;

class KeywordImportCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('init:keywords')
            ->setDescription('Import keywords to database');;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->import($output, 'keywords-general-base64.txt', 'general');
        $this->import($output, 'keywords-name-base64.txt', 'name');
    }

    private function import(OutputInterface $output, $file, $field)
    {
        $output->writeln('Importing ' . $file . '...');

        $file = base64_decode(file_get_contents(__DIR__ . '/data/' . $file));
        $keywords = [];
        $lines = explode("\n", $file);
        foreach ($lines as $line) {
            if (strlen($line) > 0) {
                $keywords[] = strtolower(trim($line));
            }
        }
        $keywords = array_values(array_unique($keywords));
        Application::coll('System')->update([
            '_id' => 'FilterKeyword'
        ], [
            '$set' => [
                strval($field) => $keywords
            ]
        ], [
            'upsert' => true
        ]);

        $output->writeln('<info>Imported ' . count($keywords) . ' keywords.</info>');
    }
}