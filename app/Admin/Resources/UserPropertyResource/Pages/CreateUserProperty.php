<?php

namespace App\Admin\Resources\CustomPropertyResource\Pages;

use App\Admin\Resources\CustomPropertyResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUserProperty extends CreateRecord
{
    protected static string $resource = CustomPropertyResource::class;
}