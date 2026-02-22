<?php

namespace App\Http\Controllers;
use App\Models\Format;

use Illuminate\Http\Request;

class ConfigController extends Controller
{
  /**
   * Display a listing of the format data.
   *
   * @return \Illuminate\Http\Response
   */
  public function getFormats()
  {
    return response()->json(
        Format::all()->map->only(['format_id', 'name'])
    );
}
}