<?php

namespace App\Enums;

/**
 * What the home photo of a category is made of.
 *
 * Automatic is the rule the store was born with, the thumbnail of the most
 * recent visible product; Chosen is a decision the administrator made and is
 * still honoured; Broken is a decision that stopped holding, the uploaded file
 * or the chosen picture no longer shows, so the automatic rule paints the
 * photo and the panel warns about it.
 */
enum HomeCategoryImageState: string
{
    case Automatic = 'automatic';
    case Chosen = 'chosen';
    case Broken = 'broken';
}
