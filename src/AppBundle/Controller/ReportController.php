<?php
namespace AppBundle\Controller;

use AppBundle\Entity\Graph;
use AppBundle\Entity\Report;
use AppBundle\Util\RecordUtil;
use MongoDB\BSON\UTCDateTime;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ReportController extends Controller
{
    private $dataDef;
    private $provider;
    private $translator;

    /**
     * @Route("/{_locale}/report/{provider}/{aspect}/{parameter}/{question}", name="report", requirements={"_locale" = "%app.locales%", "provider"="[^/]+", "aspect"="[^/]+", "parameter"="[^/]+", "question"="[^/]+"})
     */
    public function report(Request $request, $_locale = null, $provider = '', $aspect = 'completeness', $parameter = 'minimum', $question = 'overview')
    {
        $this->provider = $provider;

        if(!$_locale) {
            $_locale = $this->getParameter('locale');
            $request->setLocale($_locale);
        }
        $this->translator = $this->get('translator');
        $this->translator->setLocale($_locale);

        $leftMenu = $this->getParameter('left_menu');
        $this->dataDef = $this->getParameter('data_definition');

        $providers = $this->getDocumentManager()->getRepository('ProviderBundle:Provider')->findAll();
        $providerName = null;
        foreach($providers as $provider) {
            if($provider->getIdentifier() === $this->provider) {
                $providerName = $provider->getName();
            }
        }

        if($providerName == null) {
            throw new NotFoundHttpException();
        }

        $route = $this->generateUrl('report', array('_locale' => $_locale, 'provider' => $this->provider));
        $download = $this->generateUrl('download', array('_locale' => $_locale, 'provider' => $this->provider));
        $translatedRoutes = array();
        foreach(explode('|', $this->getParameter('app.locales')) as $locale) {
            $translatedRoute = array(
                'locale'=> $locale,
                'route' => $this->generateUrl('report', array('_locale' => $locale, 'provider' => $this->provider, 'aspect' => $aspect, 'parameter' => $parameter, 'question' => $question))
            );
            if($locale === $_locale) {
                $translatedRoute['active'] = true;
            }
            $translatedRoutes[] = $translatedRoute;
        }

        $functionCall = null;
        $parameters = $leftMenu[$aspect]['parameters'];
        foreach($parameters as $param) {
            if($param['url'] === $parameter) {
                foreach ($param['questions'] as $quest) {
                    if($quest['url'] === $question) {
                        $functionCall = $quest['function'];
                        break;
                    }
                }
            }
        }

        $report = $this->$functionCall();
        $data = array(
            'route' => $route,
            'download' => $download,
            'provider_id' => $this->provider,
            'provider_name' => $providerName,
            'providers' => $providers,
            'left_menu' => $leftMenu,
            'active_aspect' => $aspect,
            'active_parameter' => $parameter,
            'active_question' => $question,
            'report' => $report,
            'current_page' => 'home',
            'translated_routes' => $translatedRoutes
        );
        return $this->render('report.html.twig', $data);
    }

    private function getDocumentManager()
    {
        return $this->get('doctrine_mongodb')->getManager();
    }

    private function getAllRecords()
    {
        return $this->getDocumentManager()->getRepository('RecordBundle:Record')->findBy(array('provider' => $this->provider));
    }

    private function generateBarChart($csvData, $header)
    {
        $csvData = '"field","name","value","col"' . $csvData;
        return new Graph('barchart', $csvData, $header);
    }

    private function generatePieChart($pieces)
    {
        $pieChartData = '';
        foreach($pieces as $key => $value) {
            if(strlen($pieChartData) > 0) {
                $pieChartData .= ",";
            }
            $pieChartData .= '{"label":"' . $key . ' (' . $value . ')", "value":"' . $value . '"}';
        }
        return new Graph('piechart', '[' . $pieChartData . ']');
    }

    private function generatePieCharts($pieces)
    {
        $pieChartData = '';
        foreach($pieces as $key => $value) {
            $data = '';
            foreach($value as $k => $v) {
                $data .= (strlen($data) == 0 ? '' : ',') . '"' . $k . ' (' . $v . ')": "' . $v . '"';
            }
            if(strlen($data) > 0) {
                $pieChartData .= (strlen($pieChartData) == 0 ? '' : ',') . '{ ' . $data . ' }';
            }
        }
        return new Graph('piecharts', '[' . $pieChartData . ']');
    }

    private function generateLineGraph($lineChartData, $header)
    {
        return new Graph('linegraph', $lineChartData, $header);
    }

    private function getTrend($repository)
    {
        $maxMonths = $this->getParameter('trends.max_history_months');

        $curTime = new UTCDateTime();
        $curTs = $curTime->toDateTime()->getTimestamp() * 1000;
        $trend = $this->getDocumentManager()->getRepository($repository)->findBy(array(
            'provider' => $this->provider,
            "timestamp" => array('$lte' => new UTCDateTime(), '$gte' => new UTCDateTime($curTs - $maxMonths * 30 * 24 * 3600 * 1000))
        ));

        return $trend;
    }

    private function generateCompletenessTrendGraph($isMinimum, $isBasic, $header)
    {
        $trend = $this->getTrend('ReportBundle:CompletenessTrend');

        $lineChartData = 'date,value';
        foreach($trend as $dataPoint) {
            if($isMinimum) {
                $value = $dataPoint->getMinimum();
            } elseif($isBasic) {
                $value = $dataPoint->getBasic();
            }
            $lineChartData .= '\n' . $dataPoint->getTimestamp()->format('Y-m-d') . ' 00:00:00,' . $value;
        }
        return new Graph('linegraph', $lineChartData, $header);
    }

    private function generateFieldTrendGraph($type, $header)
    {
        $trend = $this->getTrend('ReportBundle:FieldTrend');

        $lineChartData = 'date,value';
        foreach($trend as $dataPoint) {
            $lineChartData .= '\n' . $dataPoint->getTimestamp()->format('Y-m-d') . ' 00:00:00,' . $dataPoint->getCounts()[$type];
        }
        return $this->generateLineGraph($lineChartData, $header);
    }

    private function fieldOverview($isMinimum, $isBasic, $isExtended, $title, $description)
    {
        $reports = $this->getDocumentManager()->getRepository('ReportBundle:FieldReport')->findBy(array('provider' => $this->provider));
        $csvData = '';
        $total = 0;
        if($reports && count($reports) > 0) {
            $report = $reports[0];
            $total = $report->getTotal();
            if($isMinimum || $isBasic) {
                $data = $report->getMinimum();
            } elseif($isExtended) {
                $data = $report->getExtended();
            }
            foreach ($data as $key => $value) {
                $label = null;
                if (strpos($key, '/')) {
                    $parts = explode('/', $key);
                    $label = $this->dataDef[$parts[0]][$parts[1]]['label'];
                    $key = $parts[0];
                } else {
                    $label = $this->dataDef[$key]['label'];
                }
                $label = $this->translator->trans($label);
                $csvData .= PHP_EOL . '"' . $key . '","' . $label . '","' . count($value) . '","' . ($isBasic ? '1"' : '0"');
            }
            if($isBasic) {
                $data = $report->getBasic();
                foreach ($data as $key => $value) {
                    $label = null;
                    if (strpos($key, '/')) {
                        $parts = explode('/', $key);
                        $label = $this->dataDef[$parts[0]][$parts[1]]['label'];
                        $key = $parts[0];
                    } else {
                        $label = $this->dataDef[$key]['label'];
                    }
                    $label = $this->translator->trans($label);
                    $csvData .= PHP_EOL . '"' . $key . '","' . $label . '","' . count($value) . '","0"';
                }
            }
        }
        $barChart = $this->generateBarChart($csvData, $this->translator->trans('filled_in_records'));
        $barChart->canDownload = true;
        $barChart->max = $total;
        if($isBasic) {
            $barChart->bottomLegend = '<div class="color-boxes"><div><div class="color1-box"></div> ' . $this->translator->trans('label_completeness_minimum') . '</div><div><div class="color0-box"></div> ' . $this->translator->trans('label_completeness_basic') . '</div></div>';
        }

        return new Report($title, $description, array($barChart));
    }

    private function fullRecords($isBasic, $isMinimum, $title, $description)
    {
        $reports = $this->getDocumentManager()->getRepository('ReportBundle:CompletenessReport')->findBy(array('provider' => $this->provider));
        $done = 0;
        $total = 0;
        if($reports && count($reports) > 0) {
            $report = $reports[0];
            $total = $report->getTotal();
            if ($isBasic) {
                $done = $report->getbasic();
            } elseif ($isMinimum) {
                $done = $report->getMinimum();
            }
        }
        $pieces = array($this->translator->trans('complete_records') => $done,  $this->translator->trans('incomplete_records') => $total - $done);
        $pieChart = $this->generatePieChart($pieces);
        if($total - $done === 0 && $done > 0) {
            $pieChart->isFull = true;
            $pieChart->fullText = $this->translator->trans('all_records_complete');
        }
        elseif($done === 0) {
            $pieChart->isEmpty = true;
            $pieChart->emptyText = $this->translator->trans('no_complete_records');
        }
        return new Report($title, $description, array($pieChart));
    }

    private function minFieldOverview()
    {
        return $this->fieldOverview(
            true,
            false,
            false,
            $this->translator->trans('label_completeness_minimum') . ' - ' . $this->translator->trans('overview_fields'),
            $this->translator->trans('description_completeness_minimum_overview')
        );
    }

    private function minFullRecords()
    {
        return $this->fullRecords(
            false,
            true,
            $this->translator->trans('label_completeness_minimum') . ' - ' . $this->translator->trans('complete_records'),
            $this->translator->trans('description_completeness_minimum_complete_records')
        );
    }

    private function minTrend()
    {
        return new Report(
            $this->translator->trans('label_completeness_minimum') . ' - ' . $this->translator->trans('history'),
            $this->translator->trans('description_completeness_minimum_trend'),
            array($this->generateCompletenessTrendGraph(
                true, false, $this->translator->trans('complete_records')
            ))
        );
    }

    private function basicFieldOverview()
    {
        return $this->fieldOverview(
            false,
            true,
            false,
            $this->translator->trans('label_completeness_basic') . ' - ' . $this->translator->trans('overview_fields'),
            $this->translator->trans('description_completeness_basic_overview')
        );
    }

    private function basicFullRecords()
    {
        return $this->fullRecords(
            true,
            false,
            $this->translator->trans('label_completeness_basic') . ' - ' . $this->translator->trans('complete_records'),
            $this->translator->trans('description_completeness_basic_complete_records')
        );
    }

    private function basicTrend()
    {
        return new Report(
            $this->translator->trans('label_completeness_basic') . ' - ' . $this->translator->trans('history'),
            $this->translator->trans('description_completeness_basic_trend'),
            array($this->generateCompletenessTrendGraph(
                false, true, $this->translator->trans('complete_records')
            ))
        );
    }

    private function extendedFieldOverview()
    {
        return $this->fieldOverview(
            false,
            false,
            true,
            $this->translator->trans('label_completeness_extended') . ' - ' . $this->translator->trans('overview_fields'),
            $this->translator->trans('description_completeness_extended_overview')
        );
    }

    private function ambigIds($field, $label, $description)
    {
        $allRecords = $this->getAllRecords();
        $counts = array();
        if($allRecords) {
            $ids = array();
            foreach ($allRecords as $record) {
                $data = $record->getData();
                if ($data[$field] && count($data[$field]) > 0) {
                    $id = $data[$field][0];
                    if (!array_key_exists($id, $ids)) {
                        $ids[$id] = 1;
                    } else {
                        $ids[$id]++;
                    }
                }
            }
            foreach ($ids as $id => $count) {
                if (!array_key_exists($count, $counts)) {
                    $counts[$count] = 1;
                } else {
                    $counts[$count]++;
                }
            }
        }
        $isGood = false;
        if(count($counts) === 1 && array_key_exists(1, $counts)) {
            $isGood = true;
        }
        $countsPie = array();
        $hasZero = false;
        foreach($counts as $key => $value) {
            if($key == 1) {
                $countsPie[$label . ' ' . $this->translator->trans('which_occur_1_time')] = $value;
            } else {
                $countsPie[$label . ' ' . $this->translator->trans('which_occur_%x%_times', array('%x%' => $key))] = $value;
            }
            if($key == 0) {
                $hasZero = true;
            }
        }
        if(!$hasZero) {
            $countsPie[$label . ' ' . $this->translator->trans('which_occur_%x%_times', array('%x%' => 0))] = 0;
        }

        $pieChart = $this->generatePieChart($countsPie);
        $pieChart->canDownload = true;
        if($isGood) {
            $pieChart->isFull = true;
            $pieChart->fullText = $this->translator->trans('all_%label%_occur_once', array('%label%' => $label));
        } elseif(count($counts) === 0) {
            $pieChart->isEmpty = true;
            $pieChart->emptyText = $this->translator->trans('no_records_with_%label%', array('%label%' => $label));
        }
        $title = $this->translator->trans('label_ambiguity'). ' ' . $label;
        return new Report($title, $description, array($pieChart));
    }

    private function ambigWorkPids()
    {
        return $this->ambigIds('work_pid', $this->translator->trans('work_pids'), $this->translator->trans('description_ambiguity_records_work_pids'));
    }

    private function ambigDataPids()
    {
        return $this->ambigIds('data_pid', $this->translator->trans('data_pids'), $this->translator->trans('description_ambiguity_records_data_pids'));
    }

    private function checkIdsAndAuthoritiesForTerm($term, $fieldValue, &$authorities, &$localIds, &$termsWithId, &$termsWithoutId)
    {
        $firstPurlId = null;
        if ($fieldValue['id'] && count($fieldValue['id']) > 0) {
            foreach ($fieldValue['id'] as $termId) {
                if (array_key_exists('source', $termId)) {
                    $id = $termId['id'];
                    $authority = $termId['source'];
                    $purlId = null;
                    if ($termId['type'] === 'purl') {
                        $purlId = $id;
                        if($firstPurlId == null) {
                            $firstPurlId = $id;
                        }
                        if (array_key_exists($authority, $authorities)) {
                            if (!in_array($term, $authorities[$authority])) {
                                $authorities[$authority][] = $term;
                            }
                        } else {
                            $authorities[$authority] = array($term);
                        }
                    } elseif ($termId['type'] === 'local') {
                        if (array_key_exists($authority, $authorities)) {
                            if (!in_array($term, $authorities[$authority])) {
                                $authorities[$authority][] = $term;
                            }
                        } else {
                            $authorities[$authority] = array($term);
                        }
                        $key = $authority . '::' . $id;
                        if (!array_key_exists($term, $localIds)) {
                            $localIds[$term] = array($key);
                        } else if (!in_array($key, $localIds[$term])) {
                            $localIds[$term][] = $key;
                        }
                    }
                    if($purlId != null) {
                        $key = $authority . '::' . $id;
                        if (!array_key_exists($term, $termsWithId)) {
                            $termsWithId[$term] = array($key);
                        } else if (!in_array($key, $termsWithId[$term])) {
                            $termsWithId[$term][] = $key;
                        }
                    }
                }
            }
        }
        if ($firstPurlId == null && !array_key_exists($term, $termsWithoutId) && !array_key_exists($term, $termsWithId)) {
            $termsWithoutId[$term] = '';
        }
    }

    private function ambigTerms($field)
    {
        $allRecords = $this->getAllRecords();
        $authorities = array();
        $localIds = array();
        $termsWithId = array();
        $termsWithoutId = array();
        if($allRecords) {
            foreach ($allRecords as $record) {
                $data = $record->getData();
                if ($data[$field] && count($data[$field]) > 0) {
                    $fieldValues = $data[$field];
                    foreach ($fieldValues as $fieldValue) {
                        if ($fieldValue['term'] && count($fieldValue['term']) > 0) {
                            $term = RecordUtil::getPreferredTerm($fieldValue['term']);
                            if($term) {
                                $this->checkIdsAndAuthoritiesForTerm($term, $fieldValue, $authorities, $localIds, $termsWithId, $termsWithoutId);
                            }
                        }
                    }
                }
            }
        }

        $totalTerms = count($termsWithId) + count($termsWithoutId);

        $counts = array();
        $idCounts = array();
        $authorityCounts = array();
        foreach($localIds as $term => $ids) {
            foreach($ids as $id) {
                if(!array_key_exists($id, $idCounts)) {
                    $idCounts[$id] = array($term);
                } else {
                    $idCounts[$id][] = $term;
                }

                $authority = explode('::', $id)[0];
                if(!array_key_exists($authority, $authorityCounts)) {
                    $authorityCounts[$authority] = array($term => array($id));
                } else if(!array_key_exists($term, $authorityCounts[$authority])) {
                    $authorityCounts[$authority][$term] = array($id);
                } else if(!in_array($id, $authorityCounts[$authority][$term])) {
                    $authorityCounts[$authority][$term][] = $id;
                }
            }
        }

        foreach($termsWithId as $term => $ids) {
            $count = count($ids);
            if(array_key_exists($count, $counts)) {
                $counts[$count]++;
            } else {
                $counts[$count] = 1;
            }

            foreach($ids as $id) {
                if(!array_key_exists($id, $idCounts)) {
                    $idCounts[$id] = array($term);
                } else {
                    $idCounts[$id][] = $term;
                }

                $authority = explode('::', $id)[0];
                if(!array_key_exists($authority, $authorityCounts)) {
                    $authorityCounts[$authority] = array($term => array($id));
                } else if(!array_key_exists($term, $authorityCounts[$authority])) {
                    $authorityCounts[$authority][$term] = array($id);
                } else if(!in_array($id, $authorityCounts[$authority][$term])) {
                    $authorityCounts[$authority][$term][] = $id;
                }
            }
        }
        ksort($counts);
        $counts[0] = count($termsWithoutId);

        $pieces = array();
        $zeroes = null;
        foreach($counts as $occurrences => $count) {
            if($occurrences == 0) {
                $zeroes = $count;
            } else if($occurrences == 1) {
                $pieces[$this->translator->trans('terms_with_1_id')] = $count;
            } else {
                $pieces[$this->translator->trans('terms_with_%x%_ids', array('%x%' => $occurrences))] = $count;
            }
        }
        ksort($pieces);
        $pieces[$this->translator->trans('terms_without_id')] = ($zeroes == null ? 0 : $zeroes);
        $termsPieChart = $this->generatePieChart($pieces);
        $termsPieChart->canDownload = true;
        if(count($termsWithoutId) === 0 && count($termsWithId) > 0) {
            $termsPieChart->isFull = true;
            $termsPieChart->fullText = $this->translator->trans('all_terms_have_an_id');
        }
        elseif(count($termsWithId) === 0) {
            $termsPieChart->isEmpty = true;
            if(count($termsWithoutId) === 0) {
                $termsPieChart->emptyText = $this->translator->trans('no_records_for_this_field');
                $termsPieChart->canDownload = false;
            } else {
                if(count($termsWithoutId) == 1) {
                    $termsPieChart->emptyText = $this->translator->trans('no_terms_with_id_1');
                } else {
                    $termsPieChart->emptyText = $this->translator->trans('no_terms_with_id_%x%', array('%x%' => count($termsWithoutId)));
                }
            }
        }

        $pieces = array();
        foreach($idCounts as $key => $ids) {
            $count = count($ids);
            if(array_key_exists($count, $pieces)) {
                $pieces[$count]++;
            } else {
                $pieces[$count] = 1;
            }
        }
        ksort($pieces);
        $emptyIdsPie = count($pieces) == 0;
        $pieces[0] = 0;

        $pieces_ = array();
        foreach($pieces as $key => $value) {
            if($key == 1) {
                $k = $this->translator->trans('ids') . ' ' . $this->translator->trans('which_occur_1_time');
            } else {
                $k = $this->translator->trans('ids') . ' ' . $this->translator->trans('which_occur_%x%_times', array('%x%' => $key));
            }
            $pieces_[$k] = $value;
        }
        $idsPieChart = $this->generatePieChart($pieces_);
        if($emptyIdsPie) {
            $idsPieChart->isEmpty = true;
        }

        $authorityPieces = array();
        foreach($authorityCounts as $authority => $terms) {
            $termsForThisAuth = 0;
            $authorityPieces[$authority] = array();
            foreach($terms as $term => $ids) {
                $termsForThisAuth++;
                $count = count($ids);
                if(!array_key_exists($count, $authorityPieces[$authority])) {
                    $authorityPieces[$authority][$count] = 1;
                } else {
                    $authorityPieces[$authority][$count]++;
                }
            }
            ksort($authorityPieces[$authority]);
            $authorityPieces[$authority][0] = $totalTerms - $termsForThisAuth;
        }

        $authorityPieces_ = array();
        foreach($authorityPieces as $authority => $values) {
            $authorityPieces_[$authority] = array();
            foreach($values as $key => $value) {
                if($key == 0) {
                    $k = $this->translator->trans('terms_without_%auth%_id', array('%auth%' => $authority));
                } elseif($key == 1) {
                    $k = $this->translator->trans('terms_with_1_%auth%_id', array('%auth%' => $authority));
                } else {
                    $k = $this->translator->trans('terms_with_%x%_%auth%_ids', array('%x%' => $key, '%auth%' => $authority));
                }
                $authorityPieces_[$authority][$k] = $value;
            }
        }
        $authorityPieCharts = $this->generatePieCharts($authorityPieces_);

        $csvData = '';
        foreach($authorities as $term => $ids) {
            $csvData .= PHP_EOL . '"' . $term . '","' . $term . '","' . count($ids) . '","0"';
        }
        $barChart = $this->generateBarChart($csvData, $this->translator->trans('ids_for_this_authority'));
        $barChart->canDownload = true;
        if(count($authorities) === 0) {
            $barChart->isEmpty = true;
            if(count($termsWithId) > 0) {
                $barChart->emptyText = $this->translator->trans('no_authorities_for_these_terms');
            }
        } else {
            $barChart->max = $totalTerms;
        }

        $lineChart = $this->generateFieldTrendGraph($field, $this->translator->trans('terms_with_id'));

        $title = $this->translator->trans('label_ambiguity') . ' ' . $this->translator->trans(RecordUtil::getFieldLabel($field, $this->dataDef));
        return new Report($title, $this->translator->trans('description_ambiguity_terms'), array($termsPieChart, $idsPieChart, $authorityPieCharts, $barChart, $lineChart));
    }

    private function ambigObjectName()
    {
        return $this->ambigTerms('object_name');
    }

    private function ambigCategory()
    {
        return $this->ambigTerms('object_category');
    }

    private function ambigMainMotif()
    {
        return $this->ambigTerms('main_motif');
    }

    private function ambigCreator()
    {
        return $this->ambigTerms('creator');
    }

    private function ambigMaterial()
    {
        return $this->ambigTerms('material');
    }

    private function ambigConcept()
    {
        return $this->ambigTerms('displayed_concept');
    }

    private function ambigSubject()
    {
        return $this->ambigTerms('displayed_subject');
    }

    private function ambigLocation()
    {
        return $this->ambigTerms('displayed_location');
    }

    private function ambigEvent()
    {
        return $this->ambigTerms('displayed_event');
    }

    private function richOccurrences($field)
    {
        $allRecords = $this->getAllRecords();
        $counts = array();
        if($allRecords) {
            foreach ($allRecords as $record) {
                $data = $record->getData();
                if ($data[$field]) {
                    $count = count($data[$field]);
                    if (array_key_exists($count, $counts)) {
                        $counts[$count]++;
                    } else {
                        $counts[$count] = 1;
                    }
                } else {
                    $count = 0;
                    if (array_key_exists($count, $counts)) {
                        $counts[$count]++;
                    } else {
                        $counts[$count] = 1;
                    }
                }
            }
        }

        ksort($counts);

        $csvData = '';
        foreach($counts as $key => $value) {
            if($key == 1) {
                $label = $this->translator->trans('label_richness_occurrences_' . $field);
            } else {
                $label = $this->translator->trans('label_richness_occurrences_' . $field . 's');
            }
            $csvData .= PHP_EOL . '"' . $key . '","' . $key . ' ' . lcfirst($label) . '","' . $value . '","0"';
        }
        $barChart = $this->generateBarChart($csvData, $this->translator->trans('amount_of_records'));
        $barChart->canDownload = true;
        if(count($counts) === 0) {
            $barChart->isEmpty = true;
            $barChart->emptyText = $this->translator->trans('no_records_for_this_field');
        }

        $title = $this->translator->trans('label_richness') . ' ' . $this->translator->trans(RecordUtil::getFieldLabel($field, $this->dataDef)) . ' in records';
        return new Report($title, $this->translator->trans('description_richness_occurrences'), array($barChart));
    }

    private function richOccurrencesStorageInstitution()
    {
        return $this->richOccurrences('storage_institution');
    }

    private function richOccurrencesObjectNumber()
    {
        return $this->richOccurrences('object_number');
    }

    private function richOccurrencesDataPid()
    {
        return $this->richOccurrences('data_pid');
    }

    private function richOccurrencesTitle()
    {
        return $this->richOccurrences('title');
    }

    private function richOccurrencesShortDesc()
    {
        return $this->richOccurrences('short_description');
    }

    private function richOccurrencesObjectName()
    {
        return $this->richOccurrences('object_name');
    }

    private function richOccurrencesObjectCat()
    {
        return $this->richOccurrences('object_category');
    }

    private function richOccurrencesMainMotif()
    {
        return $this->richOccurrences('main_motif');
    }

    private function richOccurrencesCreator()
    {
        return $this->richOccurrences('creator');
    }

    private function richOccurrencesMaterial()
    {
        return $this->richOccurrences('material');
    }

    private function richOccurrencesConcept()
    {
        return $this->richOccurrences('displayed_concept');
    }

    private function richOccurrencesSubject()
    {
        return $this->richOccurrences('displayed_subject');
    }

    private function richOccurrencesLocation()
    {
        return $this->richOccurrences('displayed_location');
    }

    private function richOccurrencesEvent()
    {
        return $this->richOccurrences('displayed_event');
    }

    private function richTerms($field)
    {
        $undefinedKey = '(undefined)';

        $allRecords = $this->getAllRecords();
        $counts = array();
        if($allRecords) {
            foreach ($allRecords as $record) {
                $data = $record->getData();
                if(array_key_exists($field, $data)) {
                    if ($data[$field] && count($data[$field]) > 0) {
                        $fieldValues = $data[$field];
                        foreach ($fieldValues as $fieldValue) {
                            if ($fieldValue['term'] && count($fieldValue['term']) > 0) {
                                $term = RecordUtil::getPreferredTerm($fieldValue['term']);
                                if ($term) {
                                    if (array_key_exists($term, $counts)) {
                                        $counts[$term]++;
                                    } else {
                                        $counts[$term] = 1;
                                    }
                                }
                            }
                        }
                    } else {
                        if (array_key_exists($undefinedKey, $counts)) {
                            $counts[$undefinedKey]++;
                        } else {
                            $counts[$undefinedKey] = 1;
                        }
                    }
                } else {
                    if (array_key_exists($undefinedKey, $counts)) {
                        $counts[$undefinedKey]++;
                    } else {
                        $counts[$undefinedKey] = 1;
                    }
                }
            }
        }

        $undefined = $counts[$undefinedKey];
        unset($counts[$undefinedKey]);
        arsort($counts);
        if($undefined > 0) {
            $counts = array($undefinedKey => $undefined) + $counts;
        }

        $csvData = '';
        foreach($counts as $key => $value) {
            if($key === '(undefined)') {
                $csvData .= PHP_EOL . '"' . $key . '","' . $this->translator->trans('undefined') . '","' . $value . '","0"';
            } else {
                $csvData .= PHP_EOL . '"' . $key . '","' . $key . '","' . $value . '","0"';
            }
        }
        $barChart = $this->generateBarChart($csvData, $this->translator->trans('amount_of_records'));
        $barChart->canDownload = true;
        if(count($counts) === 0) {
            $barChart->isEmpty = true;
            $barChart->emptyText = $this->translator->trans('no_terms_for_this_field');
        }

        $title = $this->translator->trans('label_richness') . ' ' . $this->translator->trans(RecordUtil::getFieldLabel($field, $this->dataDef));
        return new Report($title, $this->translator->trans('description_richness_terms'), array($barChart));
    }

    private function richTermObjectName()
    {
        return $this->richTerms('object_name');
    }

    private function richTermObjectCat()
    {
        return $this->richTerms('object_category');
    }

    private function richTermMainMotif()
    {
        return $this->richTerms('main_motif');
    }

    private function richTermCreator()
    {
        return $this->richTerms('creator');
    }

    private function richTermMaterial()
    {
        return $this->richTerms('material');
    }

    private function richTermConcept()
    {
        return $this->richTerms('displayed_concept');
    }

    private function richTermSubject()
    {
        return $this->richTerms('displayed_subject');
    }

    private function richTermLocation()
    {
        return $this->richTerms('displayed_location');
    }

    private function richTermEvent()
    {
        return $this->richTerms('displayed_event');
    }

    private function generateOpennessTrendGraph($isRightsWork, $isRightsDigitalRepresentation, $isRightsData, $header)
    {
        $trend = $this->getTrend('ReportBundle:CompletenessTrend');

        $lineChartData = 'date,value';
        foreach($trend as $dataPoint) {
            if($isRightsWork) {
                $value = $dataPoint->getRightsWork();
            } elseif($isRightsDigitalRepresentation) {
                $value = $dataPoint->getRightsDigitalRepresentation();
            } elseif($isRightsData) {
                $value = $dataPoint->getRightsData();
            }
            $lineChartData .= '\n' . $dataPoint->getTimestamp()->format('Y-m-d') . ' 00:00:00,' . $value;
        }
        return new Graph('linegraph', $lineChartData, $header);
    }

    private function opennessRecs($isRightsWork, $isRightsDigitalRepresentation, $isRightsData, $title, $description)
    {
        $reports = $this->getDocumentManager()->getRepository('ReportBundle:CompletenessReport')->findBy(array('provider' => $this->provider));
        $done = 0;
        $total = 0;
        if($reports && count($reports) > 0) {
            $report = $reports[0];
            $total = $report->getTotal();
            if($isRightsWork) {
                $done = $report->getRightsWork();
            } elseif($isRightsDigitalRepresentation) {
                $done = $report->getRightsDigitalRepresentation();
            } elseif($isRightsData) {
                $done = $report->getRightsData();
            }
        }
        $pieces = array($this->translator->trans('complete_records') => $done, $this->translator->trans('incomplete_records') => $total - $done);
        $pieChart = $this->generatePieChart($pieces);
        if($total - $done === 0 && $done > 0) {
            $pieChart->isFull = true;
            $pieChart->fullText = $this->translator->trans('all_records_complete');
        }
        elseif($done === 0) {
            $pieChart->isEmpty = true;
            $pieChart->emptyText = $this->translator->trans('no_complete_records');
        }

        $lineGraph = $this->generateOpennessTrendGraph(
            $isRightsWork, $isRightsDigitalRepresentation, $isRightsData, $this->translator->trans('complete_records')
        );

        return new Report($title, $description, array($pieChart, $lineGraph));
    }

    private function openRecordRecords()
    {
        $title = $this->translator->trans('label_openness') . ' ' . strtolower($this->translator->trans('label_openness_record')) . ' - ' . strtolower($this->translator->trans('label_openness_record_records'));
        return $this->opennessRecs(
            false,
            false,
            true,
            $title, $this->translator->trans('description_openness_record_records'));
    }

    private function openRecordTerms()
    {
        return $this->ambigTerms('rights_data');
    }

    private function openWorkRecords()
    {
        $title = $this->translator->trans('label_openness') . ' ' . strtolower($this->translator->trans('label_openness_work')) . ' - ' . strtolower($this->translator->trans('label_openness_work_records'));
        return $this->opennessRecs(
            true,
            false,
            false,
            $title, $this->translator->trans('description_openness_work_records'));
    }

    private function openWorkTerms()
    {
        return $this->ambigTerms('rights_work');
    }

    private function openDigRepRecords()
    {
        $title = $this->translator->trans('label_openness') . ' ' . strtolower($this->translator->trans('label_openness_digital_representation')) . ' - ' . strtolower($this->translator->trans('label_openness_digital_representation_records'));
        return $this->opennessRecs(
            false,
            true,
            false,
            $title, $this->translator->trans('description_openness_digital_representation_records'));
    }

    private function openDigRepTerms()
    {
        return $this->ambigTerms('rights_digital_representation');
    }
}
