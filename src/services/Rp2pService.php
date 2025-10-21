<?php
# Copyright 2025

/**
 * RP2P Service
 *
 * Handles all business logic for R peer-to-peer payment routing.
 *
 * @package Services
 */
class RP2pService {
    /**
     * @var P2pRepository P2P repository instance
     */
    private P2pRepository $p2pRepository;

    /**
     * @var RP2pRepository RP2P repository instance
     */
    private RP2pRepository $rp2pRepository;

    /**
     * @var UserContext Current user data
     */
    private UserContext $currentUser;

    /**
     * @var Rp2pPayload payload builder for Rp2p
     */
    private Rp2pPayload $rp2pPayload;

    /**
     * Constructor
     *
     * @param P2pRepository $p2pRepository P2P repository
     * @param RP2pRepository $rp2pRepository RP2P repository
     * @param UserContext $currentUser Current user data
     */
    public function __construct(
        P2pRepository $p2pRepository,
        RP2pRepository $rp2pRepository,
        UserContext $currentUser
    ) {
        $this->p2pRepository = $p2pRepository;
        $this->rp2pRepository = $rp2pRepository;
        $this->currentUser = $currentUser;
        $this->rp2pPayload = new Rp2pPayload($this->currentUser);
    }



    /**
     * Handle incoming RP2P request
     *
     * @param array $request The RP2P request data
     * @return void
     */
    public function handleRp2pRequest(array $request): void {
        // Handler for incoming rp2p messages
        
        // Check if corresponding p2p exists 
        $p2p = getP2pByHash($request['hash']);
        if(!$p2p){
            throw new Exception('P2P request was not found for the given hash.');
        }else{
            if(isset($p2p['destination_address'])) {
                $this->p2pRepository->updateStatus($request['hash'], 'found');
            }
            // Add users fee to request
            $request['amount'] += $p2p['my_fee_amount'];

            //Check if intermediary sender of p2p can afford to send eIOU with fees
            if(!isset($p2p['destination_address'])) {
                $availableFunds = calculateAvailableFunds($p2p);
                if($availableFunds < $request['amount']){
                    output(outputP2pUnableToAffordRp2p($p2p,$request), 'SILENT');
                }
            }

            // Save rp2p response 
            $insertResult = $this->rp2pRepository->insertRp2pRequest($request);
            if (!$insertResult) {
                output(outputRp2pInsertionFailure($request), 'SILENT');
            }
            // Check if original p2p was sent by user
            if(isset($p2p['destination_address'])) {
                $feePercent = feeInformation($p2p,$request); // Get fee percent and output fee information in  log
                
                // Check if the fee percent is below the set maximum fee percent the user would pay
                if ($feePercent <= $this->currentUser->getMaxFee()) {
                    sendP2pEiou($request); // Send transaction through rp2p chain
                } else {
                    output(outputFeeRejection(), 'SILENT');
                }
            } else{
                // Send rp2p messages onwards to sender of p2p
                $rP2pPayload =  $this->rp2pPayload->build($request); // Build rp2p payload
                $this->p2pRepository->updateStatus($request['hash'], 'found');  // Update the p2p request status to found
                $response = json_decode(send($p2p['sender_address'], $rP2pPayload),true);
                output(outputRp2pResponse($response),'SILENT');
            }
        }
    }

    /**
     * Check Rp2p Possible
     *
     * @param array|null $request Request data
     * @return bool True if RP2P possible, False otherwise.
     */
    function checkRp2pPossible($request, $echo = true){
        // Check if RP2P already exists for hash in database
        try{
            if($this->rp2pRepository->rp2pExists($request['hash'])){
              //If RP2P already exists 
                if($echo){
                    echo  $this->rp2pPayload->buildRejection($request);
                }
                return false;
            } 
            if($echo){
                echo  $this->rp2pPayload->buildAcceptance($request);
            }
            return true;  
        } catch (PDOException $e) {
            // Handle database error
            error_log("Error retrieving existence of RP2P by hash" . $e->getMessage());
            if($echo){
                echo json_encode([
                    "status" => "rejected",
                    "message" => "Could not retrieve existence of RP2P with receiver"
                ]);
            }
            return false;
        }
    }
}