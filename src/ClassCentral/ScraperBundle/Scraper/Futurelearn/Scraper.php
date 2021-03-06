<?php
/**
 * Created by PhpStorm.
 * User: dhawal
 * Date: 2/13/14
 * Time: 2:52 PM
 */

namespace ClassCentral\ScraperBundle\Scraper\Futurelearn;


use ClassCentral\ScraperBundle\Scraper\ScraperAbstractInterface;
use ClassCentral\SiteBundle\Entity\Course;
use ClassCentral\SiteBundle\Entity\Offering;
use ClassCentral\SiteBundle\Services\Kuber;


/**
 * The scraper leverages the api from kimonolabs
 * The scraper only checks for courses. Does not create or update them
 * Class Scraper
 * @package ClassCentral\ScraperBundle\Scraper\Futurelearn
 */
class Scraper extends ScraperAbstractInterface {

    const COURSES_API_ENDPOINT = 'https://www.futurelearn.com/feeds/courses';

    private $courseFields = array(
        'Url', 'Description', 'Length', 'Name','LongDescription','Certificate',
    );

    private $offeringFields = array(
        'StartDate', 'EndDate', 'Url', 'Status'
    );

    public function scrape()
    {
        $em = $this->getManager();
        $flCourses = json_decode(file_get_contents( self::COURSES_API_ENDPOINT ), true );
        $coursesChanged = array();
        foreach ($flCourses as $flCourse)
        {
            $courseChanged = false;


            $course =  $this->getCourseEntity( $flCourse );

            $dbCourse = $this->dbHelper->getCourseByShortName( $course->getShortName() );

            if(!$dbCourse)
            {
                // Course does not exist create it.
                if($this->doCreate())
                {
                    $this->out("NEW COURSE - " . $course->getName());

                    // NEW COURSE
                    if ($this->doModify())
                    {
                        $em->persist($course);
                        $em->flush();

                        if( $flCourse['image_url'] )
                        {
                            $this->uploadImageIfNecessary( $flCourse['image_url'], $course);
                        }
                    }
                    $courseChanged = true;

                }
            }
            else
            {
                // Check if any fields are modified
                $courseModified = false;
                $changedFields = array(); // To keep track of fields that have changed
                foreach($this->courseFields as $field)
                {
                    $getter = 'get' . $field;
                    $setter = 'set' . $field;
                    if($course->$getter() != $dbCourse->$getter())
                    {
                        $courseModified = true;

                        // Add the changed field to the changedFields array
                        $changed = array();
                        $changed['field'] = $field;
                        $changed['old'] =$dbCourse->$getter();
                        $changed['new'] = $course->$getter();
                        $changedFields[] = $changed;

                        $dbCourse->$setter($course->$getter());
                    }

                }

                if($courseModified && $this->doUpdate())
                {
                    //$this->out( "Database course changed " . $dbCourse->getName());
                    // Course has been modified
                    $this->out("UPDATE COURSE - " . $dbCourse->getName() . " - ". $dbCourse->getId());
                    $this->outputChangedFields($changedFields);
                    if ($this->doModify())
                    {
                        $em->persist($dbCourse);
                        $em->flush();

                        if( $flCourse['image_url'] )
                        {
                            $this->uploadImageIfNecessary( $flCourse['image_url'], $dbCourse);
                        }
                    }
                    $courseChanged = true;
                }

                $course = $dbCourse;
            }

            /***************************
             * CREATE OR UPDATE OFFERING
             ***************************/
            foreach( $flCourse['runs'] as $run)
            {
                $offering = $this->getOfferingEntity( $run, $course);
                $dbOffering = $this->dbHelper->getOfferingByShortName( $run['uuid'] );

                if (!$dbOffering)
                {
                    if($this->doCreate())
                    {
                        $this->out("NEW OFFERING - " . $offering->getName());
                        if ($this->doModify())
                        {
                            $em->persist($offering);
                            $em->flush();
                        }
                        $offerings[] = $offering;
                        $courseChanged = true;
                    }
                }
                else
                {
                    // old offering. Check if has been modified or not
                    $offeringModified = false;
                    $changedFields = array();
                    foreach ($this->offeringFields as $field)
                    {
                        $getter = 'get' . $field;
                        $setter = 'set' . $field;
                        if ($offering->$getter() != $dbOffering->$getter())
                        {
                            $offeringModified = true;
                            // Add the changed field to the changedFields array
                            $changed = array();
                            $changed['field'] = $field;
                            $changed['old'] =$dbOffering->$getter();
                            $changed['new'] = $offering->$getter();
                            $changedFields[] = $changed;
                            $dbOffering->$setter($offering->$getter());
                        }
                    }

                    if ($offeringModified && $this->doUpdate())
                    {
                        // Offering has been modified
                        $this->out("UPDATE OFFERING - " . $dbOffering->getName());
                        $this->outputChangedFields($changedFields);
                        if ($this->doModify())
                        {
                            $em->persist($dbOffering);
                            $em->flush();
                        }
                        $offerings[] = $dbOffering;
                        $courseChanged = true;
                    }
                }
            }

            if( $courseChanged )
            {
                $coursesChanged[] = $course;
            }
        }


        return $coursesChanged;
    }

    private function getOfferingEntity ($run, $course)
    {
        $offering = new Offering();
        $offering->setShortName( $run['uuid'] );
        $offering->setCourse( $course );
        $offering->setUrl( $course->getUrl() );
        if( $run['start_date'] )
        {
            $startDate = new \DateTime( $run['start_date'] );
            $endDate =  new \DateTime( $run['start_date'] );
            $days = $run['duration_in_weeks']*7;
            $endDate->add( new \DateInterval("P{$days}D") );

            $offering->setStartDate( $startDate );
            $offering->setEndDate( $endDate );
            $offering->setStatus( Offering::START_DATES_KNOWN );
        }
        else
        {
            $curYear = date('Y');
            $startDate = new \DateTime("$curYear-12-30"); // Dec 30
            $endDate = new \DateTime("$curYear-12-31"); // Dec 31

            $offering->setStartDate( $startDate );
            $offering->setEndDate( $endDate );
            $offering->setStatus( Offering::START_YEAR_KNOWN );
        }

        return $offering;
    }

    private function getCourseEntity ($c = array())
    {
        $defaultStream = $this->dbHelper->getStreamBySlug('cs');
        $langMap = $this->dbHelper->getLanguageMap();
        $defaultLanguage = $langMap[ 'English' ];

        $course = new Course();
        $course->setShortName( $c['uuid'] );
        $course->setInitiative( $this->initiative );
        $course->setName( $c['name'] );
        $course->setDescription( $c['introduction'] );
        $course->setLongDescription( $c['description'] );
        $course->setLanguage( $defaultLanguage);
        $course->setStream($defaultStream); // Default to Computer Science
        $course->setUrl($c['url']);
        $course->setCertificate( $c['has_certificates'] );
        $course->setWorkloadMin( $c['hours_per_week'] ) ;
        $course->setWorkloadMax( $c['hours_per_week'] ) ;
        // Get the length
        if( $c['runs'] )
        {
            $course->setLength( $c['runs'][0]['duration_in_weeks'] );
        }

        return $course;
    }

    private function uploadImageIfNecessary( $imageUrl, Course $course)
    {
        $kuber = $this->container->get('kuber');
        $uniqueKey = basename($imageUrl);
        if( $kuber->hasFileChanged( Kuber::KUBER_ENTITY_COURSE,Kuber::KUBER_TYPE_COURSE_IMAGE, $course->getId(),$uniqueKey ) )
        {
            // Upload the file
            $filePath = '/tmp/course_'.$uniqueKey;
            file_put_contents($filePath,file_get_contents($imageUrl));
            $kuber->upload(
                $filePath,
                Kuber::KUBER_ENTITY_COURSE,
                Kuber::KUBER_TYPE_COURSE_IMAGE,
                $course->getId(),
                null,
                $uniqueKey
            );

        }
    }

    private function outputChangedFields($changedFields)
    {
        foreach($changedFields as $changed)
        {
            $field = $changed['field'];
            $old = is_a($changed['old'], 'DateTime') ? $changed['old']->format('jS M, Y') : $changed['old'];
            $new = is_a($changed['new'], 'DateTime') ? $changed['new']->format('jS M, Y') : $changed['new'];

            $this->out("$field changed from - '$old' to '$new'");
        }
    }

} 