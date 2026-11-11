<?php

namespace App\Http\Requests;

class StoreSemesterScheduleImportRequest extends StoreScheduleImportRequest
{
    // Only the owned academic period and XLSX are client inputs. Parity is server derived.
}
