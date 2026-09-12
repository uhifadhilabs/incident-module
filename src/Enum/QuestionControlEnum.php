<?php

declare(strict_types=1);

/*
 * This file is part of the UhifadhiLabs Incidents Module.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Uhifadhi\Incident\Enum;

/**
 * HOW A BLOCK QUESTION IS ANSWERED — the six controls the report form draws, and
 * no seventh.
 *
 * The set is closed here for the same reason the blocks are: a question the form
 * cannot draw is a question the product cannot ask, and there is no form builder.
 * Each case is a rule of the house form vocabulary rather than a widget of this
 * module's own — a select is `.fsel`, a number and its unit are `.fnum`, a
 * yes/no pair is `.fyn`, a date is `.fdate`, free text is `.ftxt`.
 *
 * THE TWO SELECTS ARE DIFFERENT QUESTIONS. {@see self::SelectFixed} offers words
 * the platform knows — the five roles, the three injuries — and they are the same
 * words in every area. {@see self::SelectAreaList} offers ONE AREA'S OWN list
 * ({@see AreaListEnum}): an animal is never a typed name, and it is never the
 * neighbouring area's animal either.
 */
enum QuestionControlEnum: string
{
    case Text = 'text';
    case Number = 'number';
    case SelectFixed = 'select-fixed';
    case SelectAreaList = 'select-area-list';
    case Date = 'date';
    case YesNo = 'yes-no';
}
