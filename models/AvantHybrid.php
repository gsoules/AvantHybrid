<?php

class AvantHybrid
{
    // For development/debugging, set the border to 1 so you can see individual cells.
    const BORDER = 0;

    // This limit is documented. If you change the value here, change it in digitalarchive.us docs as well.
    const MAX_IMAGES_IN_DETAIL_LAYOUT = 1000;

    protected $detailLayoutElementNames = array();
    protected $pdf;
    protected $privateElementsData = array();
    protected $reportItems = array();
    protected $skippedColumns = array();
    protected $skipPrivateElements;

    function __construct()
    {
        $this->privateElementsData = CommonConfig::getOptionDataForPrivateElements();
        $this->skipPrivateElements = empty(current_user());

        // Get the names of public elements in the order in which they appear on a public item view page.
        $displayOrderElementNames = ElementsConfig::getOptionDataForDisplayOrder();
        foreach ($displayOrderElementNames as $displayOrderElementName)
            $this->detailLayoutElementNames[] = $displayOrderElementName;

        // Get the names of all non Dublin Core elements and add them to the list.
        $itemTypeElements = get_db()->getTable('Element')->findByItemType(AvantAdmin::getCustomItemTypeId());
        foreach ($itemTypeElements as $element)
        {
            $name = $element->name;
            if (in_array($name, $this->detailLayoutElementNames))
            {
                // Skip elements that are already in the list.
                continue;
            }

            $this->detailLayoutElementNames[] = $name;
        }
    }

    public function createReportForItem($item)
    {
        $reportItem = new AvantReportItem($item, $this->detailLayoutElementNames, $this->privateElementsData);
        $this->reportItems[] = $reportItem;

        $this->initializeReport('P', $item);

        // Emit the item title.
        $title = $this->getTitle($reportItem->getElementNameValuePairs());
        $this->pdf->SetFont('Arial','B',10);
        $this->pdf->MultiCell(0, 0.2, self::decode($title), self::BORDER);
        $this->pdf->Ln(0.2);

        // Emit the item's image if it has one.
        $imageUrl = ItemPreview::getImageUrl($item, false, false);
        if ($imageUrl)
        {
            $this->pdf->Image($imageUrl, 0.8, null, 3.5, null);
            $this->pdf->Ln(0.2);
        }

        // Determine if the item has an attachment.
        $itemFiles = $item->Files;
        if ($itemFiles)
        {
            // The item has an attachment.
            $primaryImage = $itemFiles[0];
            if (strpos($primaryImage['mime_type'], 'pdf') !== false)
            {
                // The attachment is a PDF. Emit its file name.
                $this->pdf->SetFont('Arial', '', 10);
                $this->pdf->Cell(1.0, 0.2, "PDF:", self::BORDER, 0, 'R');
                $this->pdf->SetFont('Arial', 'U', 10);
                $attachmentUrl = WEB_DIR . '/files/original/' . $primaryImage['filename'];
                $this->pdf->AddLink();
                $this->pdf->Cell(0, 0.2, $attachmentUrl, self::BORDER);
                $this->pdf->Ln(0.3);
            }
        }

        // Get the item's elements.
        $this->emitItemElements($reportItem);

        // Prompt the user to save the file.
        $this->downloadReport(__('item-') . ItemMetadata::getItemIdentifier($item));
    }

    /* @var $searchResults SearchResultsTableView */
    public function createReportForSearchResults($searchResults)
    {
        $sharedSearchEnabled = $searchResults->sharedSearchingEnabled();
        $results = $searchResults->getResults();
        foreach ($results as $index => $result)
        {
            $this->reportItems[] = new AvantReportItem($result, $this->detailLayoutElementNames, $this->privateElementsData, $sharedSearchEnabled);
        }

        $layoutId = $searchResults->getSelectedLayoutId();
        $this->initializeReport($layoutId == 1 ? 'P' : 'L');

        // Emit the search filters and selector bar options.
        $this->pdf->SetFont('Arial', '', 9);
        $filterText = $this->getFilterText($searchResults, $layoutId);
        $this->pdf->MultiCell(0, 0.2, $filterText, self::BORDER, 'L');
        $this->pdf->Ln(0.1);

        // Switch to the font that subsequent text will use.
        $this->pdf->SetTextColor(0, 0, 0);
        $this->pdf->SetFont('Arial', '', 8);

        // Emit the result rows.
        if ($searchResults->getSelectedLayoutId() == 1)
            $this->emitRowsForDetailLayout($searchResults);
        else
            $this->emitRowsForCompressedLayout($searchResults);

        // Prompt the user to save the file.
        return $this->downloadReport('search-results');
    }

    public static function decode($text)
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        return iconv('UTF-8', 'ISO-8859-1//IGNORE', $text);
    }

    protected function downloadReport($name)
    {
        // Include a cookie in the download headers that the client-side Javascript will look for to
        // determine when the download has completed so that it can show/hide a downloading message.
        setcookie("REPORT", time(), 0, '/');

        // Initiate the download.
        $fileName = $name . '.pdf';

        try
        {
            $this->pdf->Output($fileName, 'D');
        }
        catch (Exception $e)
        {
            return $e->getMessage();
        }
    }

    protected function emitElementNameValuePairs(array $elementNameValuePairs, $leftColumnWidth)
    {
        // This method emits the two-column presentation of element name and values.
        // The name appears right justified in the left column and the value appears in the right column.
        // The names of private elements, if shown, appear in gray italics.
        $previousName = '';
        foreach ($elementNameValuePairs as $elementName => $elementNameValuePair)
        {
            if ($elementNameValuePair == null)
                continue;

            foreach ($elementNameValuePair as $pair)
            {
                // Handle the case where an element has multiple values. The element's name is displayed
                // only once in the left column, and each value is displayed on a separate line in the right column.
                $name = $elementName;
                if ($name == $previousName)
                {
                    $name = '';
                }
                else
                {
                    $previousName = $name;
                    $name .= ':';

                    // Show names of private elements in gray italics.
                    if ($pair['private'])
                    {
                        $this->pdf->SetFont('Arial', 'I', 8);
                        $this->pdf->SetTextColor(120, 120, 120);
                    }
                    else
                    {
                        $this->pdf->SetFont('Arial', '', 8);
                        $this->pdf->SetTextColor(0, 0, 0);
                    }
                }

                // Emit the element name in the left column, right justified.
                $this->pdf->Cell($leftColumnWidth, 0.18, $name, self::BORDER, 0, 'R');

                // Emit the element value with normal black text, left justified. Long values will wrap in their multicell.
                $this->pdf->SetFont('Arial', '', 8);
                $this->pdf->SetTextColor(0, 0, 0);
                $rightColumnWidth = 7.0 - $leftColumnWidth;
                $value = $pair['value'];
                $this->pdf->MultiCell($rightColumnWidth, 0.18, $value, self::BORDER);
                $this->pdf->Ln(0.02);
            }
        }
    }

    protected function emitItemElements($reportItem, $leftColumnWidth = 1.0)
    {
        $elementNameValuePairs = $reportItem->getElementNameValuePairs();
        $this->emitElementNameValuePairs($elementNameValuePairs, $leftColumnWidth);
    }

    protected function emitLine($orientation, $y)
    {
        $endLine = $orientation == 'P' ? 7.70 : 10.20;
        $this->pdf->Line(0.8, $y, $endLine, $y);
    }

    protected function emitRowsForCompressedLayout($searchResults)
    {
        $useElasticsearch = $searchResults->useElasticsearch();
        $layoutId = $searchResults->getSelectedLayoutId();

        // Push the table row a little to the right so that the table's left border aligns with the page header.
        $indent = 0.05;

        // Set the widths of the report's columns.
        $layoutColumns = $searchResults->getLayoutsData()[$layoutId]['columns'];
        $this->setReportColumnWidths($indent, $layoutColumns, $searchResults->sharedSearchingEnabled());

        $rows = array();

        $avantElasticsearch = $useElasticsearch ? new AvantElasticsearch() : null;

        // Create an array containing all of the result's element values.
        foreach ($this->reportItems as $reportItem)
        {
            $elementNameValuePairs = $reportItem->getElementNameValuePairs();

            // Loop over each element column. Note that this logic does not need to check for and exclude private
            // elements because a user has to be logged in to choose a layout that contains private elements.
            $cells = array();

            foreach ($layoutColumns as $columnName)
            {
                if (in_array($columnName, $this->skippedColumns))
                    continue;

                $cells[$columnName] = '';

                foreach ($elementNameValuePairs as $elementNameValuePair)
                {
                    if ($elementNameValuePair == null)
                        continue;

                    foreach ($elementNameValuePair as $pair)
                    {
                        $name = $pair['name'];

                        // Skip any elements that are not in the layout.
                        if ($useElasticsearch)
                        {
                            $fieldName = $avantElasticsearch->convertElementNameToElasticsearchFieldName($columnName);
                            if ($name != $fieldName)
                                continue;
                        }
                        else
                        {
                            if ($name != $columnName)
                                continue;
                        }

                        // Add the element's value(s) to the cell.
                        $value = $pair['value'];
                        if (!empty($cells[$columnName]))
                        {
                            // Use EOL to separate mulitple values for the same element.
                            $cells[$columnName] .= PHP_EOL;
                        }
                        $cells[$columnName] .= $value;
                    }
                }
            }

            // Create a report row for each result.
            $row = array();
            foreach ($cells as $cell)
                $row[] = $cell;
            $rows[] = $row;
        }

        $headerRow = array();
        foreach ($rows as $index => $cells)
        {
            if ($index == 0)
            {
                // Emit the header row.
                foreach ($layoutColumns as $columnName)
                {
                    if (!in_array($columnName, $this->skippedColumns))
                        $headerRow[] = $columnName;
                }
                $this->pdf->Row($headerRow, $indent, null);
            }

            // Emit the result row.
            $this->pdf->Row($cells, $indent, $headerRow);
        }
    }

    protected function emitRowsForDetailLayout($searchResults)
    {
        $totalResults = $searchResults->getTotalResults();

        foreach ($this->reportItems as $reportItem)
        {
            $elementNameValuePairs = $reportItem->getElementNameValuePairs();

            // Determine if the next row needs to start on a new page.
            if ($this->pdf->GetY() > 8.5)
            {
                // Start the next row on a new page.
                $this->pdf->AddPage();
            }
            else
            {
                // Draw a line above the next row.
                $this->pdf->Ln(0.1);
                $this->pdf->SetDrawColor(160, 160, 160);
                $this->emitLine('P', $this->pdf->GetY());
                $this->pdf->Ln(0.1);
            }

            // Emit the title
            $title = $this->getTitle($elementNameValuePairs);
            $this->pdf->SetFont('Arial', 'B', 8);
            $this->pdf->SetTextColor(64, 64, 64);
            $this->pdf->MultiCell(0, 0.2, "$title", self::BORDER, 'L');

            if ($totalResults > self::MAX_IMAGES_IN_DETAIL_LAYOUT)
            {
                // Don't emit images in order to avoid running out of memory.
                // There is a script (http://www.fpdf.org/?go=script&id=76) that will write pages to a file as they
                // are finished and thus avoid memory limitations, but for now we use the in-memory approach.
                $indent = 0.8;
                $hasImage = false;
            }
            else
            {
                $indent = 3.5;

                // Emit the item's thumbnail image.
                $imageTop = $this->pdf->GetY();
                $thumbnailUrl = $reportItem->getThumbnailUrl();
                $hasImage = !empty($thumbnailUrl);

                if ($hasImage)
                {
                    $imageSize = getimagesize($thumbnailUrl);
                    if ($imageSize)
                    {
                        $w = $imageSize[0];
                        $h = $imageSize[1];
                        $maxImageHeight = $w / $h > 2 ? 1.0 : 1.50;
                        $maxImageHeight = $w / $h > 2 ? 1.0 : 1.00;
                        $y = $this->pdf->GetY();
                        $this->pdf->Image($thumbnailUrl, 0.8, $y + 0.05, null, $maxImageHeight);
                        $imageBottom = $y + $maxImageHeight;
                        $imagePageNo = $this->pdf->PageNo();
                        $this->pdf->SetY($imageTop);
                    }
                    else
                    {
                        $hasImage = false;
                    }
                }
            }

            // Emit the item's element names and values.
            $this->emitItemElements($reportItem, $indent);

            // If the metadata did not display below the image on the same page, move Y to below the image.
            $y = $this->pdf->GetY();
            if ($hasImage && $y < $imageBottom && $imagePageNo == $this->pdf->PageNo())
            {
                $this->pdf->SetY($imageBottom);
            }
        }
    }

    protected function getFilterText(SearchResultsTableView $searchResults, $layoutId)
    {
        // Determine if this is an All Sites search.
        $siteId = AvantCommon::queryStringArgOrCookie('site', 'SITE-ID', 0);
        $field = $layoutId == 1 ? 'field' : 'column';
        $site = $siteId == 1 ? "all sites (contributor ID appears in Identifier $field)" : WEB_DIR;

        // Get the search filters.
        $filterText = '';
        $filters = $searchResults->emitSearchFiltersText();
        $parts = explode(PHP_EOL, $filters);
        foreach ($parts as $part)
        {
            if (empty($part))
                continue;
            if ($filterText)
                $filterText .= ', ';
            $filterText .= $part;
        }

        // Get the Sort and Items settings.
        $query = $searchResults->getQuery();
        $sortField = '';

        if (isset($query['sort']))
        {
            $sortField = $query['sort'];
        }

        $imagesOnly = false;
        if (isset($query['filter']))
        {
            $imagesOnly = $query['filter'] == '1';
        }

        if (empty($sortField))
        {
            $allowSortByRelevance = !empty(AvantCommon::queryStringArg('query')) || !empty(AvantCommon::queryStringArg('keywords'));
            if ($allowSortByRelevance)
            {
                $sortField = AvantSearch::SORT_BY_RELEVANCE;
            }
            else
            {
                // Default to sort by modified date descending when no sort order specified and not allowed to sort by
                // relevance. This causes the most recently modified items to appear first.
                $sortField = AvantSearch::SORT_BY_MODIFIED;
            }
        }

        if ($filterText)
            $filterText .= ', ';
        $filterText .= "sorted by $sortField";


        if ($imagesOnly)
        {
            $filterText .= ", only items with images";
        }

        $totalResults = $searchResults->getTotalResults();
        $filterText = "$totalResults search results from $site" . PHP_EOL . "Filters: $filterText";

        return $filterText;
    }

    protected function getTitle($elementNameValuePairs)
    {
        $title = '';
        $titles = $elementNameValuePairs['Title'];
        if ($titles)
        {
            foreach ($titles as $index => $pair)
            {
                if ($index != 0)
                {
                    $title .= PHP_EOL;
                }
                $title .= $pair['value'];
            }
        }
        else
        {
            $title = __('[Untitled]');
        }
        return $title;
    }

    protected function initializeReport($orientation, $item = null)
    {
        $this->pdf = new FPDFExtended($orientation, 'in', 'letter');

        // Replace {nb} in the footer with the page number.
        $this->pdf->AliasNbPages();

        // Set the margins.
        $this->pdf->SetTopMargin(0.75);
        $this->pdf->SetLeftMargin(0.75);
        $this->pdf->SetRightMargin(0.75);

        // Add the first page. Other pages are added automatically.
        $this->pdf->AddPage();

        // Emit the organization name and item URL at the top of the page, with a line under.
        $this->pdf->SetFont('Arial','',10);
        $this->pdf->SetTextColor(80, 80, 80);
        $this->pdf->Cell(0, 0.2, get_option('site_title') . ' ' . __('Digital Archive'), self::BORDER);

        // Emit a link to the item.
        if ($item)
        {
            $this->pdf->SetFont('', 'U', 9);
            $this->pdf->AddLink();
            $url = WEB_ROOT . '/items/show/' . $item->id;
            $this->pdf->Cell(0, 0.2, $url, self::BORDER, 0, 'R');
        }

        // Emit a line, followed by spacing, under the header.
        $this->emitLine($orientation, 1.1);
        $this->pdf->Ln(0.5);
    }

    protected function setReportColumnWidths($indent, $layoutColumns, $sharedSearchingEnabled)
    {
        $widths = array();
        $availableWidth = 9.5 - ($indent * 2);
        $columnCount = count($layoutColumns);
        $columnIndex = 0;
        foreach ($layoutColumns as $layoutColumnName)
        {
            $columnIndex += 1;

            if ($availableWidth <= 0)
            {
                $this->skippedColumns[] = $layoutColumnName;
                continue;
            }

            if ($layoutColumnName == 'Identifier')
            {
                $width = $sharedSearchingEnabled ? 0.8 : 0.6;
            }
            elseif ($layoutColumnName == 'Title' || $layoutColumnName == 'Description')
            {
                $width = $columnCount > 5 ? 1.75 : 4.0;
            }
            else
            {
                $width = $columnCount > 6 ? 1.25 : 2.0;
            }

            if ($columnIndex == $columnCount)
            {
                // Give the last column all of the remaining space.
                $width = $availableWidth;
            }
            else
            {
                if ($availableWidth - $width < 0)
                {
                    $width = $availableWidth;
                }
                $availableWidth -= $width;
            }

            $widths[] = $width;
        }
        $this->pdf->SetWidths($widths);
    }
}