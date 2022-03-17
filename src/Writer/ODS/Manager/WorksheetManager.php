<?php

namespace OpenSpout\Writer\ODS\Manager;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Common\Exception\InvalidArgumentException;
use OpenSpout\Common\Exception\IOException;
use OpenSpout\Common\Helper\Escaper\ODS as ODSEscaper;
use OpenSpout\Common\Helper\StringHelper;
use OpenSpout\Writer\Common\Entity\Worksheet;
use OpenSpout\Writer\Common\Manager\RegisteredStyle;
use OpenSpout\Writer\Common\Manager\Style\StyleMerger;
use OpenSpout\Writer\Common\Manager\WorksheetManagerInterface;
use OpenSpout\Writer\ODS\Manager\Style\StyleManager;

/**
 * ODS worksheet manager, providing the interfaces to work with ODS worksheets.
 */
final class WorksheetManager implements WorksheetManagerInterface
{
    /** @var \OpenSpout\Common\Helper\Escaper\ODS Strings escaper */
    private \OpenSpout\Common\Helper\Escaper\ODS $stringsEscaper;

    /** @var StringHelper String helper */
    private StringHelper $stringHelper;

    /** @var StyleManager Manages styles */
    private StyleManager $styleManager;

    /** @var StyleMerger Helper to merge styles together */
    private StyleMerger $styleMerger;

    /**
     * WorksheetManager constructor.
     */
    public function __construct(
        StyleManager $styleManager,
        StyleMerger $styleMerger,
        ODSEscaper $stringsEscaper,
        StringHelper $stringHelper
    ) {
        $this->styleManager = $styleManager;
        $this->styleMerger = $styleMerger;
        $this->stringsEscaper = $stringsEscaper;
        $this->stringHelper = $stringHelper;
    }

    /**
     * Prepares the worksheet to accept data.
     *
     * @param Worksheet $worksheet The worksheet to start
     *
     * @throws \OpenSpout\Common\Exception\IOException If the sheet data file cannot be opened for writing
     */
    public function startSheet(Worksheet $worksheet): void
    {
        $sheetFilePointer = fopen($worksheet->getFilePath(), 'w');
        \assert(false !== $sheetFilePointer);

        $worksheet->setFilePointer($sheetFilePointer);
    }

    /**
     * Returns the table XML root node as string.
     *
     * @return string "<table>" node as string
     */
    public function getTableElementStartAsString(Worksheet $worksheet): string
    {
        $externalSheet = $worksheet->getExternalSheet();
        $escapedSheetName = $this->stringsEscaper->escape($externalSheet->getName());
        $tableStyleName = 'ta'.($externalSheet->getIndex() + 1);

        $tableElement = '<table:table table:style-name="'.$tableStyleName.'" table:name="'.$escapedSheetName.'">';
        $tableElement .= $this->styleManager->getStyledTableColumnXMLContent($worksheet->getMaxNumColumns());

        return $tableElement;
    }

    /**
     * Adds a row to the given worksheet.
     *
     * @param Worksheet $worksheet The worksheet to add the row to
     * @param Row       $row       The row to be added
     *
     * @throws InvalidArgumentException If a cell value's type is not supported
     * @throws IOException              If the data cannot be written
     */
    public function addRow(Worksheet $worksheet, Row $row): void
    {
        $cells = $row->getCells();
        $rowStyle = $row->getStyle();

        $data = '<table:table-row table:style-name="ro1">';

        $currentCellIndex = 0;
        $nextCellIndex = 1;

        for ($i = 0; $i < $row->getNumCells(); ++$i) {
            /** @var Cell $cell */
            $cell = $cells[$currentCellIndex];

            /** @var null|Cell $nextCell */
            $nextCell = $cells[$nextCellIndex] ?? null;

            if (null === $nextCell || $cell->getValue() !== $nextCell->getValue()) {
                $registeredStyle = $this->applyStyleAndRegister($cell, $rowStyle);
                $cellStyle = $registeredStyle->getStyle();
                if ($registeredStyle->isMatchingRowStyle()) {
                    $rowStyle = $cellStyle; // Replace actual rowStyle (possibly with null id) by registered style (with id)
                }

                $data .= $this->getCellXMLWithStyle($cell, $cellStyle, $currentCellIndex, $nextCellIndex);
                $currentCellIndex = $nextCellIndex;
            }

            ++$nextCellIndex;
        }

        $data .= '</table:table-row>';

        $wasWriteSuccessful = fwrite($worksheet->getFilePointer(), $data);
        if (false === $wasWriteSuccessful) {
            throw new IOException("Unable to write data in {$worksheet->getFilePath()}");
        }

        // only update the count if the write worked
        $lastWrittenRowIndex = $worksheet->getLastWrittenRowIndex();
        $worksheet->setLastWrittenRowIndex($lastWrittenRowIndex + 1);
    }

    /**
     * Closes the worksheet.
     */
    public function close(Worksheet $worksheet): void
    {
        $worksheetFilePointer = $worksheet->getFilePointer();

        fclose($worksheetFilePointer);
    }

    public function setDefaultColumnWidth(?float $width): void
    {
        $this->styleManager->setDefaultColumnWidth($width);
    }

    public function setDefaultRowHeight(?float $height): void
    {
        $this->styleManager->setDefaultRowHeight($height);
    }

    /**
     * @param int ...$columns One or more columns with this width
     */
    public function setColumnWidth(float $width, int ...$columns): void
    {
        $this->styleManager->setColumnWidth($width, ...$columns);
    }

    /**
     * @param float $width The width to set
     * @param int   $start First column index of the range
     * @param int   $end   Last column index of the range
     */
    public function setColumnWidthForRange(float $width, int $start, int $end): void
    {
        $this->styleManager->setColumnWidthForRange($width, $start, $end);
    }

    /**
     * Applies styles to the given style, merging the cell's style with its row's style.
     *
     * @throws InvalidArgumentException If a cell value's type is not supported
     */
    private function applyStyleAndRegister(Cell $cell, Style $rowStyle): RegisteredStyle
    {
        $isMatchingRowStyle = false;
        if ($cell->getStyle()->isEmpty()) {
            $cell->setStyle($rowStyle);

            $possiblyUpdatedStyle = $this->styleManager->applyExtraStylesIfNeeded($cell);

            if ($possiblyUpdatedStyle->isUpdated()) {
                $registeredStyle = $this->styleManager->registerStyle($possiblyUpdatedStyle->getStyle());
            } else {
                $registeredStyle = $this->styleManager->registerStyle($rowStyle);
                $isMatchingRowStyle = true;
            }
        } else {
            $mergedCellAndRowStyle = $this->styleMerger->merge($cell->getStyle(), $rowStyle);
            $cell->setStyle($mergedCellAndRowStyle);

            $possiblyUpdatedStyle = $this->styleManager->applyExtraStylesIfNeeded($cell);
            if ($possiblyUpdatedStyle->isUpdated()) {
                $newCellStyle = $possiblyUpdatedStyle->getStyle();
            } else {
                $newCellStyle = $mergedCellAndRowStyle;
            }

            $registeredStyle = $this->styleManager->registerStyle($newCellStyle);
        }

        return new RegisteredStyle($registeredStyle, $isMatchingRowStyle);
    }

    private function getCellXMLWithStyle(Cell $cell, Style $style, int $currentCellIndex, int $nextCellIndex): string
    {
        $styleIndex = $style->getId() + 1; // 1-based

        $numTimesValueRepeated = ($nextCellIndex - $currentCellIndex);

        return $this->getCellXML($cell, $styleIndex, $numTimesValueRepeated);
    }

    /**
     * Returns the cell XML content, given its value.
     *
     * @param Cell $cell                  The cell to be written
     * @param int  $styleIndex            Index of the used style
     * @param int  $numTimesValueRepeated Number of times the value is consecutively repeated
     *
     * @throws InvalidArgumentException If a cell value's type is not supported
     *
     * @return string The cell XML content
     */
    private function getCellXML(Cell $cell, int $styleIndex, int $numTimesValueRepeated): string
    {
        $data = '<table:table-cell table:style-name="ce'.$styleIndex.'"';

        if (1 !== $numTimesValueRepeated) {
            $data .= ' table:number-columns-repeated="'.$numTimesValueRepeated.'"';
        }

        if ($cell->isString()) {
            $data .= ' office:value-type="string" calcext:value-type="string">';

            $cellValueLines = explode("\n", $cell->getValue());
            foreach ($cellValueLines as $cellValueLine) {
                $data .= '<text:p>'.$this->stringsEscaper->escape($cellValueLine).'</text:p>';
            }

            $data .= '</table:table-cell>';
        } elseif ($cell->isBoolean()) {
            $value = ((bool) $cell->getValue()) ? 'true' : 'false'; // boolean-value spec: http://docs.oasis-open.org/office/v1.2/os/OpenDocument-v1.2-os-part1.html#datatype-boolean
            $data .= ' office:value-type="boolean" calcext:value-type="boolean" office:boolean-value="'.$value.'">';
            $data .= '<text:p>'.$cell->getValue().'</text:p>';
            $data .= '</table:table-cell>';
        } elseif ($cell->isNumeric()) {
            $cellValue = $this->stringHelper->formatNumericValue($cell->getValue());
            $data .= ' office:value-type="float" calcext:value-type="float" office:value="'.$cellValue.'">';
            $data .= '<text:p>'.$cellValue.'</text:p>';
            $data .= '</table:table-cell>';
        } elseif ($cell->isDate()) {
            $value = $cell->getValue();
            if ($value instanceof \DateTimeInterface) {
                $datevalue = substr((new \DateTimeImmutable('@'.$value->getTimestamp()))->format(\DateTimeInterface::W3C), 0, -6);
                $data .= ' office:value-type="date" calcext:value-type="date" office:date-value="'.$datevalue.'Z">';
                $data .= '<text:p>'.$datevalue.'Z</text:p>';
            } elseif ($value instanceof \DateInterval) {
                // workaround for missing DateInterval::format('c'), see https://stackoverflow.com/a/61088115/53538
                static $f = ['M0S', 'H0M', 'DT0H', 'M0D', 'Y0M', 'P0Y', 'Y0M', 'P0M'];
                static $r = ['M', 'H', 'DT', 'M', 'Y0M', 'P', 'Y', 'P'];
                $value = rtrim(str_replace($f, $r, $value->format('P%yY%mM%dDT%hH%iM%sS')), 'PT') ?: 'PT0S';
                $data .= ' office:value-type="time" office:time-value="'.$value.'">';
                $data .= '<text:p>'.$value.'</text:p>';
            } else {
                throw new InvalidArgumentException('Trying to add a date value with an unsupported type: '.\gettype($cell->getValue()));
            }
            $data .= '</table:table-cell>';
        } elseif ($cell->isError() && \is_string($cell->getValueEvenIfError())) {
            // only writes the error value if it's a string
            $data .= ' office:value-type="string" calcext:value-type="error" office:value="">';
            $data .= '<text:p>'.$cell->getValueEvenIfError().'</text:p>';
            $data .= '</table:table-cell>';
        } elseif ($cell->isEmpty()) {
            $data .= '/>';
        } else {
            throw new InvalidArgumentException('Trying to add a value with an unsupported type: '.\gettype($cell->getValue()));
        }

        return $data;
    }
}
