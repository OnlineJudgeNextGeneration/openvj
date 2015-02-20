<?php
/**
 * This file is part of openvj project.
 *
 * Copyright 2013-2015 openvj dev team.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace VJ\Vote;

class VoteUtil
{
    public static function attachDocument(array $document)
    {
        $document['voting'] = 0;
        $document['votes'] = [];
        return $document;
    }
}