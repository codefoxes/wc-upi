import QRCode from 'qrcode'

function validate( { pa, pn } ) {
	if ( ! pa || ! pn ) return "Virtual payee's address/name is compulsory"
	if ( pa.length < 5 || pn.length < 4 ) return "Virtual payee's address/name is too short."
	return ''
}

export default function upiqr( {
	payeeVPA: pa,
	payeeName: pn,
	payeeMerchantCode: mc,
	transactionId: tid,
	transactionRef: tr,
	transactionNote: tn,
	amount: am,
	minimumAmount: mam,
	currency: cu,
}, qrOptions ) {
	const params = Object.assign( { pa, pn }, Object.fromEntries( Object.entries( { am, mam, cu, mc, tid, tr, tn } ).filter( ( [_, value] ) => value ) ) )
	const error = validate( params )
	if ( error ) return Promise.reject( new Error( error ) )

	const intent = 'upi://pay?' + new URLSearchParams( params ).toString()

	return new Promise( ( resolve, reject ) => {
		QRCode
			.toDataURL( intent, qrOptions )
			.then( ( base64Data ) => resolve( { qr: base64Data, intent } ) )
			.catch( err => reject( new Error( "Unable to generate UPI QR Code.\n" + err ) ) )
	} )
}
