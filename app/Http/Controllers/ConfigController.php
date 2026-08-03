<?php

namespace App\Http\Controllers;

use App\Models\Format;
use Illuminate\Http\Response;

class ConfigController extends Controller
{
    /**
     * Display a listing of the format data.
     *
     * @return Response
     */
    public function getFormats()
    {
        // The capability flags ship with the format so the forms can ask "does
        // this need a runtime?" without a name match or a hardcoded id.
        return response()->json(
            Format::all()->map->only([
                'format_id',
                'name',
                'expects_page_count',
                'expects_audio_runtime',
            ])
        );
    }
}
