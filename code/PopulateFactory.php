<?php

/**
 * @package populate
 */
class PopulateFactory extends FixtureFactory
{

    /**
     * Creates the object in the database as the original object will be wiped.
     *
     * @param string $class
     * @param string $identifier
     * @param array $data
     */
    public function createObject($class, $identifier, $data = null)
    {
        DB::alteration_message("Creating $identifier ($class)", "created");
        
        if ($data) {
            foreach ($data as $k => $v) {
                if (!(is_array($v)) && preg_match('/^`(.)*`;$/', $v)) {
                    $str = substr($v, 1, -2);
                    $pv = null;

                    eval("\$pv = $str;");

                    $data[$k] =    $pv;
                }
            }
        }

        // for files copy the source dir if the image has a 'PopulateFileFrom'
        if (isset($data['PopulateFileFrom'])) {
            $folder = Folder::find_or_make(
                str_replace('assets/', '', dirname($data['Filename']))
            );

            @copy(
                BASE_PATH . '/'. $data['PopulateFileFrom'],
                BASE_PATH . '/'. $data['Filename']
            );
        }

        // if any merge labels are defined then we should create the object
        // from that 
        $lookup = null;
        $mode = null;

        if (isset($data['PopulateMergeWhen'])) {
            $mode = 'PopulateMergeWhen';

            $lookup = DataList::create($class)->where(
                $data['PopulateMergeWhen']
            );

            unset($data['PopulateMergeWhen']);
        } elseif (isset($data['PopulateMergeMatch'])) {
            $mode = 'PopulateMergeMatch';
            $filter = array();

            foreach ($data['PopulateMergeMatch'] as $field) {
                $filter[$field] = $data[$field];
            }

            if (!$filter) {
                throw new Exception('Not a valid PopulateMergeMatch filter');
            }

            $lookup = DataList::create($class)->filter($filter);
    
            unset($data['PopulateMergeMatch']);
        } elseif (isset($data['PopulateMergeAny'])) {
            $mode = 'PopulateMergeAny';
            $lookup = DataList::create($class);

            unset($data['PopulateMergeAny']);
        }

        if ($lookup && $lookup->count() > 0) {
            $existing = $lookup->first();
        
            foreach ($lookup as $old) {
                if ($old->ID == $existing->ID) {
                    continue;
                }
                
                if ($old->hasExtension('Versioned')) {
                    foreach ($old->getVersionedStages() as $stage) {
                        $old->deleteFromStage($stage);
                    }
                }

                $old->delete();
            }

            $blueprint = new FixtureBlueprint($class);
            $obj = $blueprint->createObject($identifier, $data, $this->fixtures);
            $latest = $obj->toMap();
            
            unset($latest['ID']);

            $existing->update($latest);
            $existing->write();

            $obj->delete();
            
            $this->fixtures[$class][$identifier] = $existing->ID;

            $obj = $existing;
            $obj->flushCache();
        } else {
            $obj = parent::createObject($class, $identifier, $data);
        }

        if ($obj->hasExtension('Versioned')) {
            foreach ($obj->getVersionedStages() as $stage) {
                if ($stage !== $obj->getDefaultStage()) {
                    $obj->writeToStage($obj->getDefaultStage());
                    $obj->publish($obj->getDefaultStage(), $stage);
                }
            }

            $obj->flushCache();
        }

        return $obj;
    }
}
