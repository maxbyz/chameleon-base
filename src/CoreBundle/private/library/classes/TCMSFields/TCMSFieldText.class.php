<?php

/*
 * This file is part of the Chameleon System (https://www.chameleonsystem.com).
 *
 * (c) ESONO AG (https://www.esono.de)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * a long text field.
/**/
class TCMSFieldText extends TCMSField
{
    /**
     * view path for frontend.
     */
    protected $sViewPath = 'TCMSFields/views/TCMSFieldText';

    /**
     * {@inheritdoc}
     */
    public function GetHTML()
    {
        parent::GetHTML();

        return $this->renderTextArea($this->data, false);
    }

    private function renderTextArea(string $data, bool $readOnly): string
    {
        $cssParts = [];
        if ('100%' !== $this->fieldCSSwidth) {
            $cssParts[] = 'width:'.$this->fieldCSSwidth;
        }

        if ('' !== $data) {
            $lineCount = count(explode("\n", $data));
            $height = min(300, 18 * ($lineCount + 2)); // 18 should correspond to the actual line height
            if ($height > 100) {
                $cssParts[] = 'height:'.$height.'px';
            }
        }

        $cssStyle = \implode(';', $cssParts);

        $html = '';
        $html .= sprintf(
            '<textarea id="%s" name="%s" class="fieldtext form-control form-control-sm resizable" width="%s" style="%s" %s>',
            TGlobal::OutHTML($this->name),
            TGlobal::OutHTML($this->name),
            $this->fieldWidth,
            $cssStyle,
            true === $readOnly ? 'readonly' : ''
        );
        $html .= TGlobal::OutHTML($data);
        $html .= '</textarea>';

        return $html;
    }

    /**
     * {@inheritdoc}
     */
    public function GetReadOnly()
    {
        // todo: remove need to call _GetFieldWidth here
        // instead of just returning the field width, the method sets some properties on $this - which are needed
        // by renderTextArea.
        $this->_GetFieldWidth();

        return $this->renderTextArea($this->data, true);
    }

    /**
     * {@inheritdoc}
     */
    public function HasContent()
    {
        $bHasContent = false;
        if ('' != trim($this->data)) {
            $bHasContent = true;
        }

        return $bHasContent;
    }
}
