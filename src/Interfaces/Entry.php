<?php
namespace BulkImport\Interfaces;

interface Entry extends \Iterator, \ArrayAccess, \Countable, \JsonSerializable
{
}
