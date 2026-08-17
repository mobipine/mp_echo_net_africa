<?php

namespace App\Exports\CreditUtilization\Concerns;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

trait StylesCreditReportSheet
{
    protected function styleTabularSheet(Worksheet $sheet, int $lastColumn, int $lastRow): void
    {
        $lastColumnLetter = Coordinate::stringFromColumnIndex($lastColumn);

        $sheet->setShowGridlines(false);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:{$lastColumnLetter}{$lastRow}");
        $sheet->getRowDimension(1)->setRowHeight(28);
        $sheet->getStyle("A1:{$lastColumnLetter}1")->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size' => 10,
            ],
            'fill' => [
                'fillType' => 'solid',
                'startColor' => ['rgb' => '163A2B'],
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ]);

        if ($lastRow > 1) {
            $sheet->getStyle("A2:{$lastColumnLetter}{$lastRow}")->applyFromArray([
                'font' => ['color' => ['rgb' => '26352F'], 'size' => 10],
                'borders' => [
                    'bottom' => [
                        'borderStyle' => Border::BORDER_HAIR,
                        'color' => ['rgb' => 'DDE5E0'],
                    ],
                ],
                'alignment' => ['vertical' => Alignment::VERTICAL_TOP],
            ]);
        }

        $sheet->getPageSetup()->setOrientation('landscape');
        $sheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getPageMargins()->setTop(0.4)->setRight(0.3)->setBottom(0.4)->setLeft(0.3);
    }
}
