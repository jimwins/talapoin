<?php

declare(strict_types=1);

namespace Talapoin\Model;

class EntryTag extends \Talapoin\Model
{
    // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore
    public static $_table = 'entry_to_tag';

    // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore
    public static $_id_column = 'rowid';
}
